<?php
/**
 * 表单查询服务实现
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use Psr\Container\ContainerInterface;
use Slim\App;
use SlimCMS\Core\Form\DTO\FormDTO;
use SlimCMS\Core\Redis;
use SlimCMS\Core\Table;
use SlimCMS\Core\Request;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\Time;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Traits\Url;

/**
 * 表单查询服务（R）
 *
 * 职责：dataList / dataView / dataCount / searchCondition / enumSubids / enumsData
 * 编排：钩子分发 + Schema 服务 + Order 校验 + 值转换
 */
final class FormQueryService implements FormQueryServiceInterface
{
    use Url;

    private ContainerInterface $container;
    private Redis $redis;
    private FormSchemaServiceInterface $schema;
    private TableHookDispatcher $hookDispatcher;
    private OrderValidator $orderValidator;
    private \Psr\Http\Message\ServerRequestInterface $request;
    /** @var OutputInterface */
    private $output;

    public function __construct(
        ContainerInterface $container,
        Redis $redis,
        FormSchemaServiceInterface $schema,
        TableHookDispatcher $hookDispatcher,
        OrderValidator $orderValidator,
        OutputInterface $output,
    ) {
        $this->container = $container;
        $this->redis = $redis;
        $this->schema = $schema;
        $this->hookDispatcher = $hookDispatcher;
        $this->orderValidator = $orderValidator;
        $this->request = $container->get(\Psr\Http\Message\ServerRequestInterface::class);
        $this->output = $output;
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

    public function list(array $param): OutputInterface
    {
        if (empty($param['fid'])) {
            return $this->output->withCode(21002);
        }
        $form = $this->schema->getForm((int)$param['fid']);
        $hook = $this->hookDispatcher->forForm($form);

        $rs = $hook->dataListInit($param);
        if (!$this->hookOk($rs, $code, $msg)) {
            return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
        }

        $param['currenturl'] = $this->url();
        $param['get'] = [];
        $arr = ['where' => []];
        if (empty($param['noinput'])) {
            $arr = $this->buildSearchCondition($param)->getData();
            $param['get'] = $arr['get'];
            $param['currenturl'] .= $arr['currentUrl'];
        }

        $data = null;
        if (!empty($param['cacheTime'])) {
            $para = $param;
            unset($para['currenturl']);
            $cachekey = $this->cacheKey('dataList', $para, $arr['where']);
            $data = $this->redis->get($cachekey);
        }

        if (empty($data)) {
            if ($form->cpCheck) {
                $ischeck = $param['ischeck'] ?? null;
                if ($ischeck) {
                    $param['get']['ischeck'] = $ischeck;
                    $where = ['ischeck' => $ischeck];
                    $param['where'] = !empty($param['where']) ? array_merge((array)$param['where'], $where) : $where;
                    empty($param['noinput']) && $param['currenturl'] .= '&ischeck=' . $ischeck;
                }
            }

            if (empty($param['noinput'])) {
                $id = $param['id'] ?? null;
                $id = $id && strpos((string)$id, '`') ? array_map('intval', explode('`', $id)) : (int)$id;
                if ($id) {
                    $where = ['id' => $id];
                    $param['where'] = !empty($param['where']) ? array_merge((array)$param['where'], $where) : $where;
                    empty($param['noinput']) && $param['currenturl'] .= '&id=' . (is_array($id) ? implode('`', $id) : $id);
                }
            }

            if (empty($param['fields'])) {
                $collection = $this->schema->getFields($form->id, ['inlistcp' => true]);
                $fields = array_map(fn($f) => $f->identifier, iterator_to_array($collection));
                $fields[] = 'createtime';
                $fields[] = 'ischeck';
                $fields[] = 'id';
                $param['fields'] = implode(',', $fields);
            }
            if (!empty($param['joinFields'])) {
                $param['fields'] = 'main.' . str_replace(',', ',main.', $param['fields']) . ',' . $param['joinFields'];
            }

            $param['where'] = !empty($param['where']) ? array_merge($arr['where'], $param['where']) : $arr['where'];

            $rs = $hook->dataListBefore($param);
            if (!$this->hookOk($rs, $code, $msg)) {
                return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
            }

            $order = (string)($param['order'] ?? '');
            $orderForce = (bool)($param['orderForce'] ?? false);
            $order = $this->orderValidator->validate($form->id, $order, $orderForce);
            $by = (string)($param['by'] ?? 'desc');
            $page = (int)($param['page'] ?? 1);
            $fields = (string)($param['fields'] ?? '');
            $pagesize = (int)($param['pagesize'] ?? 30);
            $indexField = (string)($param['indexField'] ?? '');
            $joins = (array)($param['joins'] ?? []);
            $groupby = (string)($param['groupby'] ?? '');
            $extendFormName = $param['extendFormName'] ?? null;

            $table = $this->table($form->table, $extendFormName);
            $data = $table
                ->withJoin($joins)
                ->withWhere($param['where'])
                ->withGroupby($groupby)
                ->withOrderby($order, $by)
                ->withLimit($pagesize, $page)
                ->pageList($fields, 0, $indexField);

            $fieldSchemas = $this->schema->getFields($form->id);
            $rawSchemas = $fieldSchemas->toRawArray();
            foreach ($data['list'] as &$v) {
                isset($v['id']) && $v['id'] = (int)$v['id'];
                isset($v['createtime']) && $v['createtime'] = (int)$v['createtime'];
                isset($v['ischeck']) && $v['ischeck'] = (int)$v['ischeck'];
                isset($v['ischeck']) && $v['_ischeck'] = $v['ischeck'] == 1 ? '已审核' : '未审核';
                if (!empty($rawSchemas)) {
                    $v = FormValueTransformer::exchange($rawSchemas, $v, $this->container);
                }
            }
            if (!empty($arr['tags'])) {
                $data['tags'] = $arr['tags'];
            }
            $data['form'] = $form->raw;
            $data['fid'] = $form->id;
            $data['order'] = $order;
            $data['by'] = $by;
            $data['currenturl'] = $param['currenturl'];
            $data['get'] = $param['get'];
            $data['where'] = $param['where'];

            $rs = $hook->dataListAfter($data, $param);
            if (!$this->hookOk($rs, $code, $msg)) {
                return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
            }

            !empty($param['cacheTime']) && !empty($data['list']) && $this->redis->set($cachekey, $data, $param['cacheTime']);
        }
        return $this->output->withCode(200)->withData($data);
    }

    public function view(int $fid, int $id, string $fields = '*', int $cacheTime = 0, array $options = []): OutputInterface
    {
        if (empty($fid) || empty($id)) {
            return $this->output->withCode(21002);
        }
        $cachekey = $this->cacheKey('dataView', $fid, $id);
        $data = $cacheTime > 0 ? $this->redis->get($cachekey) : [];
        if (empty($data)) {
            $form = $this->schema->getForm($fid);
            $hook = $this->hookDispatcher->forForm($form);

            $rs = $hook->dataViewBefore($id, $options);
            if (!$this->hookOk($rs, $code, $msg)) {
                return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
            }

            $row = $this->table($form->table)->withWhere(['id' => $id])->fetch($fields);
            if (empty($row)) {
                return $this->output->withCode(21001);
            }
            if (!is_array($row)) {
                $row = [$fields => $row];
            }
            $fieldSchemas = $this->schema->getFields($fid);
            $rawSchemas = $fieldSchemas->toRawArray();
            if (!empty($rawSchemas)) {
                $row = FormValueTransformer::exchange($rawSchemas, $row, $this->container);
            }

            $rs = $hook->dataViewAfter($row, $options);
            if (!$this->hookOk($rs, $code, $msg)) {
                return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
            }

            $data = ['row' => $row, 'form' => $form->raw, 'fields' => $rawSchemas];
            $cacheTime && $this->redis->set($cachekey, $data, $cacheTime);
        }
        return $this->output->withData($data)->withCode(200);
    }

    public function count(array $param): OutputInterface
    {
        if (empty($param['fid'])) {
            return $this->output->withCode(21002);
        }
        $arr = ['where' => []];
        if (empty($param['noinput'])) {
            $arr = $this->buildSearchCondition($param)->getData();
        }
        if (!empty($param['cacheTime'])) {
            $cachekey = $this->cacheKey('dataCount', $param, $arr['where']);
            $data = $this->redis->get($cachekey);
        }
        if (empty($data)) {
            $form = $this->schema->getForm((int)$param['fid']);
            $hook = $this->hookDispatcher->forForm($form);

            $rs = $hook->dataCountBefore($param);
            if (!$this->hookOk($rs, $code, $msg)) {
                return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
            }

            $where = !empty($param['where']) ? array_merge($param['where'], $arr['where']) : $arr['where'];
            $countFields = (string)($param['countFields'] ?? '');
            $data = [];
            $data['count'] = $this->table($form->table)->withWhere($where)->count($countFields);
            $data['form'] = $form->raw;
            $data['fid'] = $form->id;
            !empty($param['cacheTime']) && $this->redis->set($cachekey, $data, $param['cacheTime']);
        }
        return $this->output->withCode(200)->withData($data);
    }

    public function buildSearchCondition(array $param): OutputInterface
    {
        if (empty($param['fid'])) {
            return $this->output->withCode(21002);
        }
        $fid = (int)$param['fid'];
        $searchFields = !empty($param['searchFields'])
            ? $this->schema->getFields($fid, ['identifier' => explode(',', $param['searchFields'])])->toRawArray()
            : $this->schema->getFields($fid, ['search' => true])->toRawArray();
        $allFields = $this->schema->getFields($fid)->toRawArray();
        $data = $this->extractInputValues($allFields);

        $where = [];
        $tags = [];
        $currenturl = '';
        foreach ($searchFields as $v) {
            $arr = ['func', 'where', 'formid', 'order', 'fields', 'by', 'join', 'joinFields', 'cacheTime', 'url', 'page', 'pagesize', 'maxpages', 'autogoto', 'shownum'];
            if (!empty($param[$v['identifier']]) && !in_array($v['identifier'], $arr)) {
                $data[$v['identifier']] = $param[$v['identifier']];
            }

            $val = $data[$v['identifier']] ?? null;
            if (empty($v['rules']) && $val && preg_match('/,/', (string)$val)) {
                [$s, $e] = explode(',', $val);
                if (is_numeric($s) && is_numeric($e)) {
                    $where[] = [$v['identifier'] => ['between', $val]];
                } elseif (is_numeric($s)) {
                    $where[] = [$v['identifier'] => ['>=', $s]];
                } elseif (is_numeric($e)) {
                    $where[] = [$v['identifier'] => ['<=', $e]];
                }
            } elseif ($v['datatype'] == 'checkbox') {
                if (!empty($val)) {
                    foreach (explode(',', (string)$val) as $val1) {
                        $where[] = [$v['identifier'] => ['find', $val1]];
                    }
                }
            } elseif (in_array($v['datatype'], ['text', 'multitext', 'htmltext'])) {
                if (!empty($val)) {
                    if (!empty($v['precisesearch'])) {
                        $where[$v['identifier']] = $val;
                    } else {
                        $where[] = [$v['identifier'] => ['like', $val]];
                    }
                }
            } elseif ($v['datatype'] == 'stepselect') {
                if (!empty($val)) {
                    $where[$v['identifier']] = $this->enumSubidsRaw($v['egroup'], (int)$val);
                }
            } elseif (in_array($v['datatype'], ['radio', 'select'])) {
                if (!empty($val) || $val == '0') {
                    $where[$v['identifier']] = $val;
                }
            } else {
                if (!empty($val)) {
                    if (preg_match('/,/', (string)$val)) {
                        $where[$v['identifier']] = explode(',', $val);
                    } else {
                        if (!empty($v['precisesearch'])) {
                            $where[$v['identifier']] = $val;
                        } else {
                            $where[] = [$v['identifier'] => ['like', $val]];
                        }
                    }
                }
            }

            $rules = !empty($v['rules']) ? json_decode($v['rules'], true) : [];
            if (is_array($rules) && !empty($rules[$val])) {
                $tags[] = [$v['identifier'], $rules[$val]];
            } elseif (!empty($rules) && !is_array($val) && preg_match('/,/', (string)$val)) {
                $tags[] = [$v['identifier'], str_replace(',', '-', $val) . ($v['units'] ?? '')];
            }

            if (isset($val)) {
                if (strpos((string)$val, ',') !== false) {
                    if ($v['datatype'] == 'date') {
                        [$s, $e] = explode(',', (string)$val);
                        $sdate = $s ? Time::gmdate($s) : '';
                        $edate = $e ? Time::gmdate($e) : '';
                        $currenturl .= '&' . $v['identifier'] . '_s=' . $sdate . '&' . $v['identifier'] . '_e=' . $edate;
                        $data[$v['identifier'] . '_s'] = $sdate;
                        $data[$v['identifier'] . '_e'] = $edate;
                    } elseif ($v['datatype'] == 'datetime') {
                        [$s, $e] = explode(',', (string)$val);
                        $sdate = $s ? Time::gmdate($s, 'dt') : '';
                        $edate = $e ? Time::gmdate($e, 'dt') : '';
                        $currenturl .= '&' . $v['identifier'] . '_s=' . $sdate . '&' . $v['identifier'] . '_e=' . $edate;
                        $data[$v['identifier'] . '_s'] = $sdate;
                        $data[$v['identifier'] . '_e'] = $edate;
                    } else {
                        $val = str_replace(',', '`', (string)$val);
                        $currenturl .= '&' . $v['identifier'] . '=' . $val;
                    }
                } elseif ($v['datatype'] == 'stepselect') {
                    $currenturl .= '&' . $v['egroup'] . '=' . $val;
                } elseif ($v['datatype'] == 'month') {
                    $val = $val ? date('Y-m', (int)$val) : '';
                    $data[$v['identifier']] = $val;
                    $currenturl .= '&' . $v['identifier'] . '=' . $val;
                } else {
                    $currenturl .= '&' . $v['identifier'] . '=' . $val;
                }
            }
        }

        $data = ['tags' => $tags, 'fields' => $allFields, 'where' => $where, 'currentUrl' => $currenturl, 'get' => $data];
        return $this->output->withCode(200)->withData($data);
    }

    public function enumSubids(string $egroup, int $evalue = 0): OutputInterface
    {
        if (empty($egroup)) {
            return $this->output->withCode(21002);
        }
        $list = $this->enumSubidsRaw($egroup, $evalue);
        return $this->output->withCode(200)->withData(['ids' => $list]);
    }

    public function enumsData(string $egroup): OutputInterface
    {
        $result = [];
        if ($egroup) {
            $list = $this->table('sysenum')->withWhere(['egroup' => $egroup])->withOrderby('displayorder')->fetchList('id,ename,evalue,reid');
            if (!empty($list)) {
                foreach ($list as $k => $v) {
                    if (empty($v['evalue'])) {
                        unset($list[$k]);
                    }
                }
            }
            $result = ['list' => $list];
        }
        return $this->output->withCode(200)->withData($result);
    }

    /**
     * 从请求中抽取所有字段输入（替换原 getFormValue 在 searchCondition 中的用途）
     */
    private function extractInputValues($fields): array
    {
        $data = [];
        $request = $this->request();
        foreach ($fields as $v) {
            $val = $request->input($v['identifier']);
            if (isset($val)) {
                $data[$v['identifier']] = $val;
            }
        }
        return $data;
    }

    /**
     * 递归取联动子 ID（私有内部实现）
     */
    private function enumSubidsRaw(string $egroup, int $evalue = 0): array
    {
        $list = $this->table('sysenum')->withWhere(['egroup' => $egroup, 'reid' => $evalue])->onefieldList('evalue');
        foreach ($list as $v) {
            $list = array_merge($list, $this->enumSubidsRaw($egroup, (int)$v));
        }
        $list[] = $evalue;
        return array_unique($list);
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
        // Request 内部封装了 input() 类型转换，通过容器解析保持与控制器一致
        $request = $this->container->get(Request::class);
        return $request->setRequest($this->request);
    }

    private function cacheKey(string $key, ...$param): string
    {
        return __CLASS__ . ':' . $key . ':' . md5(serialize($param));
    }
}
