<?php
/**
 * 表单写入服务实现
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use Psr\Container\ContainerInterface;
use Slim\App;
use SlimCMS\Core\Form\DTO\FormDTO;
use SlimCMS\Core\Redis;
use SlimCMS\Core\Request;
use SlimCMS\Core\Table;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\Crypt;
use SlimCMS\Helper\Http;
use SlimCMS\Helper\Ipdata;
use SlimCMS\Helper\Str;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Interfaces\UploadInterface;

/**
 * 表单写入服务（CUD）
 *
 * 职责：dataSave / dataCheck / dataDel / delAttachment / getFormValue / requiredCheck / validCheck
 * 编排：钩子分发 + Schema 服务 + 输入提取（getFormValue） + 附件清理
 */
final class FormWriteService implements FormWriteServiceInterface
{
    private ContainerInterface $container;
    private Redis $redis;
    private FormSchemaServiceInterface $schema;
    private TableHookDispatcher $hookDispatcher;
    private FormQueryServiceInterface $query;
    /** @var OutputInterface */
    private $output;
    private \Psr\Http\Message\ServerRequestInterface $request;
    private array $config;
    /** @var UploadInterface */
    private $uploader;

    public function __construct(
        ContainerInterface $container,
        Redis $redis,
        FormSchemaServiceInterface $schema,
        TableHookDispatcher $hookDispatcher,
        FormQueryServiceInterface $query,
        OutputInterface $output,
    ) {
        $this->container = $container;
        $this->redis = $redis;
        $this->schema = $schema;
        $this->hookDispatcher = $hookDispatcher;
        $this->query = $query;
        $this->output = $output;
        $this->request = $container->get(\Psr\Http\Message\ServerRequestInterface::class);
        $this->config = $container->get('cfg');
        $this->uploader = $container->get(UploadInterface::class);
    }

    /**
     * 同步中间件处理后的 request（含 csrfToken 等 attributes）
     */
    public function setRequest(\Psr\Http\Message\ServerRequestInterface $request): self
    {
        $this->request = $request;
        $this->hookDispatcher->setRequest($request);
        return $this;
    }

    public function save(int $fid, $row = [], array $data = [], array $options = []): OutputInterface
    {
        if ($this->output->getCode() != 200) {
            return $this->output;
        }
        if ($row && is_numeric($row)) {
            $res = $this->query->view($fid, (int)$row);
            if ($res->getCode() != 200) {
                return $res;
            }
            $val = $res->getData();
            $row = $val['row'];
            $form = FormDTO::fromRow($val['form']);
            $fields = $val['fields'];
        } else {
            $row = $row ?: [];
            $form = $this->schema->getForm($fid);
            $fields = $this->schema->getFields($fid)->toRawArray();
        }
        $hook = $this->hookDispatcher->forForm($form);
        $extendFormName = isset($options['extendFormName']) && $options['extendFormName'] !== '' ? (string)$options['extendFormName'] : null;

        $rs = $hook->dataSaveInit($fields, $data, $row, $options);
        if (!$this->hookOk($rs, $code, $msg)) {
            return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
        }

        $res = $this->requiredCheck($fid, $row, $data);
        if ($res->getCode() != 200) {
            return $res;
        }

        $data = $data ?: $this->getFormValue($fields, $row);

        // 唯一性检测
        $uniqueFields = $this->schema->getFields($fid, ['unique' => true])->toRawArray();
        foreach ($uniqueFields as $v) {
            $identifier = $v['identifier'];
            if (empty($data[$identifier])) {
                continue;
            }
            $exist_id = $this->table($form->table)->withWhere([$identifier => $data[$identifier]])->fetch('id');
            if ($exist_id && (empty($row['id']) || $exist_id != ($row['id'] ?? null))) {
                return $this->output->withCode(22004);
            }
        }

        $rs = $hook->dataSaveBefore($data, $row, $options);
        if (!$this->hookOk($rs, $code, $msg)) {
            return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
        }

        if (!empty($row['id'])) {
            $this->table($form->table, $extendFormName)->withWhere(['id' => $row['id']])->update($data);
            $data['id'] = $row['id'];
            $data['mngtype'] = 'edit';
            $this->redis->del($this->cacheKey('dataView', $fid, $row['id']));
        } else {
            empty($data['createtime']) && $data['createtime'] = TIMESTAMP;
            empty($data['ip']) && $data['ip'] = Ipdata::getip($this->request);
            $data['id'] = $this->table($form->table, $extendFormName)->insert($data, true);
            $data['mngtype'] = 'add';
            $row = $data;
        }

        $rs = $hook->dataSaveAfter($data, $row, $options);
        if (!$this->hookOk($rs, $code, $msg)) {
            return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
        }
        return $this->output->withCode(200, 21018)->withData(['id' => $data['id']]);
    }

    public function check(int $fid, array $ids, int $ischeck = 1, array $options = []): OutputInterface
    {
        if (empty($fid) || empty($ids)) {
            return $this->output->withCode(21002);
        }
        $ids = array_map('intval', $ids);
        $form = $this->schema->getForm($fid);
        $hook = $this->hookDispatcher->forForm($form);

        $rs = $hook->dataCheckBefore($ids, $ischeck, $options);
        if (!$this->hookOk($rs, $code, $msg)) {
            return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
        }

        $this->table($form->table)->withWhere(['id' => $ids])->update(['ischeck' => $ischeck]);

        $rs = $hook->dataCheckAfter($ids, $ischeck, $options);
        if (!$this->hookOk($rs, $code, $msg)) {
            return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
        }
        return $this->output->withCode(200, 21032);
    }

    public function delete(int $fid, array $ids, array $options = []): OutputInterface
    {
        if (empty($fid) || empty($ids)) {
            return $this->output->withCode(21002);
        }
        $ids = array_map('intval', $ids);
        $form = $this->schema->getForm($fid);
        $hook = $this->hookDispatcher->forForm($form);

        $list = $this->table($form->table)->withWhere(['id' => $ids])->fetchList();
        if (empty($list)) {
            return $this->output->withCode(21001);
        }
        foreach ($list as $v) {
            $rs = $hook->dataDelBefore($v, $options);
            if (!$this->hookOk($rs, $code, $msg)) {
                return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
            }

            // 归档
            if ($form->isArchive) {
                $this->save(7, '', ['formid' => $fid, 'aid' => $v['id'], 'content' => json_encode($v)]);
            }

            // 删除附件
            if ($this->config['isDelAttachment'] == '1') {
                $fields = $this->schema->getFields($fid, ['datatype' => ['htmltext', 'imgs', 'img', 'media', 'addon', 'superfile', 'addons']]);
                if (!$fields->isEmpty()) {
                    $this->deleteAttachments($fields->toRawArray(), $v);
                }
            }

            $rs = $hook->dataDelAfter($v, $options);
            if (!$this->hookOk($rs, $code, $msg)) {
                return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
            }
        }
        $this->table($form->table)->withWhere(['id' => $ids])->delete();
        $hook->dataDelRealAfter($list);
        return $this->output->withCode(200, 21023);
    }

    public function deleteAttachments(array $fields, array $data): OutputInterface
    {
        if (empty($fields) || empty($data)) {
            return $this->output->withCode(21002);
        }
        $setting = $this->container->get('settings');
        foreach ($fields as $v) {
            if (empty($data[$v['identifier']])) {
                continue;
            }
            switch ($v['datatype']) {
                case 'htmltext':
                    $pattern = '/(\\' . rtrim($setting['attachment']['dirname'], '/') . '.+?)(\"|\|| )/';
                    preg_match_all($pattern, stripslashes($data[$v['identifier']]) . ' ', $delname);
                    $delname = array_unique($delname['1']);
                    foreach ($delname as $var) {
                        $this->uploader->uploadDel($var);
                    }
                    break;
                case 'imgs':
                    if (!empty($data[$v['identifier']]) && Str::isJson($data[$v['identifier']])) {
                        foreach (json_decode($data[$v['identifier']], true) as $p) {
                            $this->uploader->uploadDel($p['img']);
                        }
                    }
                    break;
                case 'addons':
                    if (!empty($data[$v['identifier']]) && Str::isJson($data[$v['identifier']])) {
                        foreach (json_decode($data[$v['identifier']], true) as $p) {
                            $this->uploader->uploadDel($p['url']);
                        }
                    }
                    break;
                default:
                    $this->uploader->uploadDel($data[$v['identifier']]);
                    break;
            }
        }
        return $this->output->withCode(200);
    }

    public function requiredCheck(int $fid, array $row = [], array $data = []): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        $requireds = $this->schema->getFields($fid, ['required' => true])->toRawArray();
        $request = $this->request();
        foreach ($requireds as $v) {
            $msg = $v['errormsg'] ?: $v['title'];
            $val = $data[$v['identifier']] ?? $request->input($v['identifier']);
            $val = $val ?: (!empty($v['egroup']) ? (int)$request->input($v['egroup']) : '');
            if (empty($row[$v['identifier']]) && !$val && !in_array($v['datatype'], ['img', 'media', 'addon', 'addons', 'imgs', 'superfile'])) {
                return $this->output->withCode(21008, '“' . $msg . '”不能为空！');
            }
        }
        return $this->output->withCode(200);
    }

    public function validCheck(int $fid, array $data, int $id = 0): array
    {
        if (empty($fid)) {
            throw new TextException(27010);
        }
        $form = $this->schema->getForm($fid);
        $fields = $this->schema->getFields($fid)->toRawArray();
        foreach ($fields as $v) {
            $msg = $v['errormsg'] ?: $v['title'];
            if ($v['required'] == 1 && empty($data[$v['identifier']])) {
                throw new TextException(21000, $v['title'] . '必填');
            }
            if ($v['unique'] == 1 && !empty($data[$v['identifier']])) {
                $exist_id = $this->table($form->table)->withWhere([$v['identifier'] => $data[$v['identifier']]])->fetch('id');
                if ($exist_id && (empty($id) || $exist_id != $id)) {
                    throw new TextException(21000, $v['title'] . '已存在');
                }
            }
            if ($v['datatype'] == 'stepselect') {
                $v['rules'] = [];
                foreach ($this->query->enumsData($v['egroup'])->getData()['list'] as $v1) {
                    $v['rules'][$v1['evalue']] = $v1['ename'];
                }
            } elseif (!empty($v['rules'])) {
                $v['rules'] = json_decode($v['rules'], true);
                if (count($v['rules']) == 1) {
                    $v['rules'] = $this->schema->resolveDynamicRules($v['rules']);
                }
            }
            if (!empty($v['rules']) && in_array($v['datatype'], ['select', 'radio', 'stepselect'])) {
                if (!empty($data[$v['identifier']]) && !array_key_exists($data[$v['identifier']], $v['rules'])) {
                    throw new TextException(21000, $msg . '值不正确');
                }
            }
        }
        return $data;
    }

    /**
     * 表单提交数据提取
     *
     * 替换原 Forms::getFormValue()。逻辑保持等价，按 datatype 分派。
     */
    private function getFormValue(array $fields, array $olddata = []): array
    {
        $cfg = $this->config;
        $data = [];
        $request = $this->request();
        foreach ($fields as $v) {
            if ($v['infront'] != 1) {
                continue;
            }
            if (!empty($olddata['id']) && ($v['datatype'] == 'readonly' || $v['forbidedit'] == 2)) {
                continue;
            }
            $identifier = $v['identifier'];
            if (!empty($v['rules'])) {
                $v['rules'] = json_decode($v['rules'], true);
            }
            if (!empty($v['rules']) && count($v['rules']) == 1) {
                $v['rules'] = $this->schema->resolveDynamicRules($v['rules']);
            }

            if (!empty($v['rules']) && in_array($v['datatype'], ['checkbox', 'select', 'radio'])) {
                $val = $request->input($identifier);
                if (isset($val)) {
                    if (is_array($val)) {
                        $vals = $val;
                    } else {
                        $vals = $val || $val == '0' ? explode('`', $val) : [];
                    }
                    foreach ($vals as $valItem) {
                        if (array_key_exists($valItem, $v['rules'])) {
                            $data[$identifier][] = $valItem;
                        }
                    }
                    $data[$identifier] = !empty($data[$identifier]) ? implode(',', $data[$identifier]) : null;
                }
                if ($v['datatype'] == 'checkbox') {
                    $data[$identifier] = !empty($data[$identifier]) ? $data[$identifier] : null;
                }
            } else {
                switch ($v['datatype']) {
                    case 'htmltext':
                        $val = (string)$request->input($identifier, 'htmltext');
                        if (isset($val)) {
                            $data[$identifier] = Str::filterHtml($val);
                        }
                        break;
                    case 'int':
                        $val = $request->input($identifier);
                        if ($val && is_array($val)) {
                            $val = array_map('intval', $val);
                            $data[$identifier] = implode(',', $val);
                        } elseif ($val && strpos((string)$val, '`')) {
                            $arr = explode('`', $val);
                            $val = array_map('intval', $arr);
                            $data[$identifier] = implode(',', $val);
                        } else {
                            $val = $request->input($identifier, 'int');
                            if (isset($val)) {
                                $data[$identifier] = $val;
                            }
                        }
                        break;
                    case 'stepselect':
                        $val = $request->input($v['egroup'], 'int');
                        if (isset($val)) {
                            $data[$identifier] = $val;
                        }
                        break;
                    case 'float':
                    case 'tel':
                    case 'price':
                        $val = $request->input($identifier, $v['datatype']);
                        if (isset($val)) {
                            $data[$identifier] = $val;
                        }
                        break;
                    case 'month':
                    case 'date':
                    case 'datetime':
                        $vals = $request->input($identifier . '_s');
                        $vale = $request->input($identifier . '_e');
                        if ($vals || $vale) {
                            $data[$identifier] = ($vals ? strtotime($vals) : '') . ',' . ($vale ? strtotime($vale) : '');
                        } else {
                            $val = $request->input($identifier);
                            if (isset($val)) {
                                $data[$identifier] = strtotime($val);
                            }
                        }
                        break;
                    case 'imgs':
                        $imgurls = [];
                        if (!empty($olddata[$identifier])) {
                            $imgurls = json_decode($olddata[$identifier], true) ?: [];
                            foreach ($imgurls as $_k => $_v) {
                                $_v['text'] = str_replace("'", "`", $request->input('imgmsg' . $_k));
                                $imgurls[$_k] = $_v;
                            }
                        }
                        if (Http::clientType($this->request) > 0) {
                            for ($i = 0; $i < 10; $i++) {
                                $picUrl = $request->input($identifier . '_' . $i, 'img');
                                if ($picUrl) {
                                    $info = $this->uploader->metaInfo($picUrl, 'url,width')->getData();
                                    $key = md5($picUrl);
                                    $imgurls[$key]['img'] = $picUrl;
                                    $imgurls[$key]['text'] = '';
                                    $imgurls[$key]['width'] = $info['width'];
                                    $imgurls[$key]['height'] = $info['height'];
                                }
                            }
                        } else {
                            $res = $this->uploader->getWebupload();
                            if ($res->getCode() == 200) {
                                $imgurls += (array)$res->getData();
                            }
                        }
                        $data[$identifier] = $imgurls ? json_encode($imgurls) : '';
                        break;
                    case 'img':
                    case 'media':
                    case 'addon':
                    case 'superfile':
                        $val = $request->input($identifier);
                        $rule = '';
                        if (!empty($cfg['whitePicUrl'])) {
                            $func = function ($val) {
                                return str_replace('/', '\/', trim($val));
                            };
                            $rule = '^' . implode('|^', array_map($func, explode("\n", $cfg['whitePicUrl'])));
                        }
                        if ($val && $rule && preg_match('/' . $rule . '/', (string)$val)) {
                            $data[$identifier] = $val;
                        } else {
                            $data[$identifier] = $request->input($identifier, $v['datatype']);
                            if (!empty($olddata[$identifier])) {
                                if (empty($data[$identifier])) {
                                    unset($data[$identifier]);
                                } else {
                                    $this->uploader->uploadDel($olddata[$identifier]);
                                }
                            }
                        }
                        break;
                    case 'addons':
                        $addons = [];
                        if (!empty($olddata[$identifier])) {
                            $addons = json_decode($olddata[$identifier], true);
                        }
                        $uploads = $this->request->getUploadedFiles();
                        if (!empty($uploads[$identifier])) {
                            foreach ($uploads[$identifier] as $v1) {
                                $res = $this->uploader->upload($v1, 'addon');
                                if ($res->getCode() == 200) {
                                    $addons[] = [
                                        'url' => $res->getData()['fileurl'] ?: '',
                                        'text' => $v1->getClientFilename(),
                                    ];
                                }
                            }
                        }
                        $data[$identifier] = $addons ? json_encode($addons) : '';
                        break;
                    case 'serialize':
                        $val = $request->input($identifier);
                        $data[$identifier] = is_array($val) ? json_encode($val) : Str::htmlspecialchars($val, 'de');
                        break;
                    case 'password':
                        $val = $request->input($identifier);
                        $val && $data[$identifier] = Crypt::pwd($val);
                        break;
                    default:
                        $val = $request->input($identifier);
                        if (isset($val) && $val !== '') {
                            $data[$identifier] = $val;
                        } elseif (isset($val)) {
                            // 空字符串不写入，让 MySQL 使用列默认值
                            // 避免整型列收到 '' 导致严格模式 1366 错误
                            $data[$identifier] = null;
                        }
                        break;
                }
            }
        }
        return $data;
    }

    /**
     * 钩子返回值统一判定
     */
    private function hookOk(int|array $rs, ?int &$code, ?string &$msg): bool
    {
        if (is_array($rs)) {
            $code = $rs['code'] ?? 0;
            $msg = $rs['msg'] ?? '';
            return $code === 200;
        }
        $code = (int)$rs;
        $msg = '';
        return $code === 200;
    }

    private function table(string $name, ?string $extendName = null): Table
    {
        // 解析 app\Table\*Table 子类，保证子类 setTableName 分表覆写生效（如 adminlog 按年份分表）
        $classname = '\app\Table\\' . ucfirst($name) . 'Table';
        if (!class_exists($classname)) {
            $classname = Table::class;
        }
        $app = $this->container->get(App::class);
        $instance = $this->container->make($classname, ['app' => $app]);
        $instance->setRequest($this->request);
        return $instance->setTableName($name, $extendName);
    }

    private function request(): Request
    {
        $request = $this->container->get(Request::class);
        return $request->setRequest($this->request);
    }

    private function cacheKey(string $key, ...$param): string
    {
        return __CLASS__ . ':' . $key . ':' . md5(serialize($param));
    }
}
