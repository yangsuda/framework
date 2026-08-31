<?php
/**
 * 表单视图渲染服务实现
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use Psr\Container\ContainerInterface;
use SlimCMS\Core\Redis;
use SlimCMS\Core\Request;
use SlimCMS\Core\Ueditor;
use SlimCMS\Helper\Http;
use SlimCMS\Helper\Str;
use SlimCMS\Helper\Time;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Interfaces\UploadInterface;

/**
 * 表单视图渲染服务
 *
 * 职责：dataFormHtml / formHtml / listFields / searchFields / orderFields
 * 这是唯一注入 OutputInterface::withTemplate 的子服务。
 */
final class FormViewRenderer implements FormViewRendererInterface
{
    private ContainerInterface $container;
    private Redis $redis;
    private FormSchemaServiceInterface $schema;
    private FormQueryServiceInterface $query;
    private TableHookDispatcher $hookDispatcher;
    private OrderValidator $orderValidator;
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
        FormQueryServiceInterface $query,
        TableHookDispatcher $hookDispatcher,
        OrderValidator $orderValidator,
        OutputInterface $output,
    ) {
        $this->container = $container;
        $this->redis = $redis;
        $this->schema = $schema;
        $this->query = $query;
        $this->hookDispatcher = $hookDispatcher;
        $this->orderValidator = $orderValidator;
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
        return $this;
    }

    public function renderFormHtml(int $fid, $row = [], array $options = []): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        if ($row && is_numeric($row)) {
            $res = $this->query->view($fid, $row);
            if ($res->getCode() != 200) {
                return $res;
            }
            $val = $res->getData();
            $row = $val['row'];
            $form = \SlimCMS\Core\Form\DTO\FormDTO::fromRow($val['form']);
        } else {
            $row = [];
            $form = $this->schema->getForm($fid);
        }

        $criteria = ['available' => true];
        if (($options['infront'] ?? false) === true) {
            $criteria['infront'] = true;
        }
        $fields = $this->schema->getFields($fid, $criteria);
        $rawFields = $fields->toRawArray();
        if (empty($row)) {
            $row = [];
            $request = $this->request();
            foreach ($rawFields as $v) {
                $row[$v['identifier']] = $request->input($v['identifier']);
            }
        }

        $hook = $this->hookDispatcher->forForm($form);
        $rs = $hook->getFormHtmlBefore($rawFields, $row, $form->raw, $options);
        if (!$this->hookOk($rs, $code, $msg)) {
            return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
        }

        $cachekey = $this->cacheKey('dataFormHtml', $fid, $options);
        $data = $this->redis->get($cachekey);
        if (empty($data) || $row) {
            $fieldshtml = $this->renderFields($fid, $rawFields, $row, $options);

            $rs = $hook->getFormHtmlAfter($fieldshtml, $rawFields, $row, $options);
            if (!$this->hookOk($rs, $code, $msg)) {
                return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
            }

            $data = ['fields' => $rawFields, 'fieldshtml' => $fieldshtml, 'data' => $row, 'form' => $form->raw, 'fid' => $fid];
            empty($row) && !empty($options['cacheTime']) && $this->redis->set($cachekey, $data, $options['cacheTime']);
        }
        return $this->output->withCode(200)->withData($data);
    }

    public function listFields(int $fid, int $limit = 30, string $fieldName = 'inlistcp'): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        $listFields = $this->schema->getFields($fid, [$fieldName => true], $limit);
        return $this->output->withCode(200)->withData(['listFields' => $listFields->toRawArray()]);
    }

    public function searchFields(int $fid, string $fields = ''): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        $searchFields = !empty($fields)
            ? $this->schema->getFields($fid, ['identifier' => explode(',', $fields)])
            : $this->schema->getFields($fid, ['search' => true]);
        $rows = $searchFields->toRawArray();
        if (!empty($rows)) {
            $request = $this->request();
            foreach ($rows as &$v) {
                if (!empty($v['rules']) && count(json_decode($v['rules'], true)) == 1) {
                    $v['rules'] = json_encode($this->schema->resolveDynamicRules(json_decode($v['rules'], true)));
                } elseif ($v['datatype'] == 'stepselect') {
                    $v['default'] = $request->input($v['egroup'], 'int');
                    static $loadonce = 0;
                    $loadonce++;
                    $v['loadonce'] = $loadonce;
                    $template = 'block/fieldshtml/' . $v['datatype'];
                    $v['fieldHtml'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                }
            }
        }
        return $this->output->withCode(200)->withData(['searchFields' => $rows]);
    }

    public function orderFields(int $fid): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        $fields = $this->schema->getFields($fid, ['orderby' => true]);
        return $this->output->withCode(200)->withData(['orderFields' => array_column($fields->toRawArray(), 'id')]);
    }

    public function allValidFields(int $fid): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        $fields = $this->schema->allValidFields($fid);
        return $this->output->withCode(200)->withData(['allValidFields' => $fields->toRawArray()]);
    }

    /**
     * 渲染字段表单 HTML（替换原 formHtml）
     */
    private function renderFields(int $fid, array $fields, array $row = [], array $options = []): array
    {
        foreach ($fields as $k => $v) {
            $v['maxlength'] = !empty($v['maxlength']) ? 'maxlength="' . $v['maxlength'] . '"' : '';
            $v['rules'] = !empty($v['rules']) ? json_decode($v['rules'], true) : [];

            $datatype = $v['datatype'];
            if ($datatype == 'int' && !empty($v['rules']) && count($v['rules']) == 1) {
                $datatype = 'select';
            }
            if (!empty($v['rules']) && count($v['rules']) == 1) {
                $v['rules'] = $this->schema->resolveDynamicRules($v['rules']);
            }

            $v['default'] = !empty($row[$v['identifier']]) ? $row[$v['identifier']] : html_entity_decode((string)$v['default']);
            $v['csrfToken'] = $this->request->getAttribute('csrfToken');

            $v['checkrule'] = (empty($v['checkrule']) && $v['required'] == 1) ? '*' : $v['checkrule'];
            if ($v['required'] == 1) {
                $text = in_array($v['datatype'], ['select', 'radio', 'checkbox']) ? '请选择' : ($v['datatype'] == 'img' ? '请上传' : '请输入');
                empty($v['nullmsg']) && $v['nullmsg'] = $text . $v['title'];
                empty($v['tip']) && $v['tip'] = $text . $v['title'];
            }
            $datatypeStr = !empty($v['checkrule']) ? 'datatype="' . $v['checkrule'] . '" ' : '';
            if (empty($v['intro']) && $datatypeStr) {
                $v['intro'] = in_array($v['datatype'], ['select', 'radio', 'checkbox']) ? '必选' : '';
            }
            $nullmsg = !empty($v['nullmsg']) ? 'nullmsg="' . $v['nullmsg'] . '" ' : '';
            $ignore = empty($v['required']) ? 'ignore="ignore" ' : '';
            $tip = !empty($v['tip']) ? 'placeholder="' . $v['tip'] . '" ' : '';
            $errormsg = !empty($v['errormsg']) ? 'errormsg="' . $v['errormsg'] . '" ' : '';
            $readonly = !empty($row['id']) && $v['forbidedit'] == 2 ? ' readonly' : '';
            $v['validform'] = ' sucmsg="" ' . $datatypeStr . $nullmsg . $tip . $errormsg . $ignore . $readonly;

            $template = 'block/fieldshtml/' . $datatype;
            switch ($datatype) {
                case 'map':
                    static $isloadMapJs = 0;
                    $isloadMapJs++;
                    $v['isloadMapJs'] = $isloadMapJs;
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'select':
                    static $isloadSelect2 = 0;
                    $isloadSelect2++;
                    $v['isloadSelect2'] = $isloadSelect2;
                    $v['default'] = strpos((string)$v['default'], ',') ? explode(',', $v['default']) : $v['default'];
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'htmltext':
                    if (Http::clientType($this->request) > 0) {
                        $v['default'] = str_replace(['&lt;br /&gt;', '&lt;br&gt;'], "\n", $v['default']);
                        $v['default'] = stripslashes($v['default']);
                        $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    } else {
                        $v['default'] = stripslashes($v['default']);
                        $config = ['identity' => $options['ueditorType'] ?? 'small'];
                        $v['field'] = $this->container->get(Ueditor::class)->setRequest($this->request)->ueditor($v['identifier'], $v['default'], $config);
                    }
                    break;
                case 'stepselect':
                    static $loadonce = 0;
                    $loadonce++;
                    $v['loadonce'] = $loadonce;
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'month':
                case 'date':
                case 'datetime':
                    if ($v['datatype'] == 'month') {
                        if (!empty($row[$v['identifier']])) {
                            $v['default'] = date('Y-m', (int)$row[$v['identifier']]);
                        } else {
                            $v['default'] = !empty($v['default']) ? $v['default'] : (empty($row) ? date('Y-m', TIMESTAMP) : '');
                        }
                    } else {
                        $type = $v['datatype'] == 'date' ? 'd' : 'dt';
                        if (!empty($row[$v['identifier']])) {
                            $v['default'] = Time::gmdate($row[$v['identifier']], $type);
                        } else {
                            $v['default'] = !empty($v['default']) ? Time::gmdate(TIMESTAMP + $v['default'] * 86400, $type) : (empty($row) ? Time::gmdate(TIMESTAMP, $type) : '');
                        }
                    }
                    static $isLoadDatetimepicker = 0;
                    $isLoadDatetimepicker++;
                    $v['isLoadDatetimepicker'] = $isLoadDatetimepicker;
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'multidate':
                    static $isLoadMultidate = 0;
                    $isLoadMultidate++;
                    $v['isLoadMultidate'] = $isLoadMultidate;
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'imgs':
                    $v['imgs'] = !empty($v['default']) ? json_decode($v['default'], true) : [];
                    $v['fid'] = $fid;
                    $v['row'] = $row;
                    $bigfile_info = $this->session()->get('bigfile_info');
                    if (!empty($bigfile_info) && is_array($bigfile_info)) {
                        foreach ($bigfile_info as $s_v) {
                            $this->uploader->uploadDel($s_v);
                        }
                    }
                    $v['copyImage'] = function ($pic, int $width = 2000, int $height = 2000) {
                        return $this->uploader->copyImage($pic, $width, $height);
                    };
                    $this->session()->delete('bigfile_info');
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'img':
                    static $isLoadh5upload = 0;
                    $isLoadh5upload++;
                    $v['isLoadh5upload'] = $isLoadh5upload;
                    $v['fid'] = $fid;
                    $v['row'] = $row;
                    $v['copyImage'] = function (int $width = 2000, int $height = 2000) use ($v) {
                        return $this->uploader->copyImage($v['default'], $width, $height);
                    };
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'serialize':
                    $val = var_export(json_decode($v['default'], true), true);
                    $v['val'] = nl2br(str_replace(["array (\n", "),\n", ")"], '', $val));
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'int':
                    $v['default'] = (int)$v['default'];
                    // no break，int 后 fall through 到 float/default
                case 'float':
                    $v['default'] = (float)$v['default'];
                    // no break
                default:
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
            }
            $fields[$k] = $v;
        }
        return $fields;
    }

    private function request(): Request
    {
        return $this->container->get(Request::class)->setRequest($this->request);
    }

    private function session(): \SlimCMS\Core\Session
    {
        return $this->container->get(\SlimCMS\Core\Session::class);
    }

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

    private function cacheKey(string $key, ...$param): string
    {
        return __CLASS__ . ':' . $key . ':' . md5(serialize($param));
    }
}
