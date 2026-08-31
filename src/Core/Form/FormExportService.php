<?php
/**
 * 表单导出服务实现
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use Psr\Container\ContainerInterface;
use SlimCMS\Core\Redis;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\File;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Traits\Url;

/**
 * 表单导出服务
 *
 * 职责：dataExport / exportData
 * 拆分点：export() 生成文件，streamDownload() 用 StreamInterface 分块输出。
 */
final class FormExportService implements FormExportServiceInterface
{
    use Url;

    private ContainerInterface $container;
    private Redis $redis;
    private FormSchemaServiceInterface $schema;
    private FormQueryServiceInterface $query;
    private TableHookDispatcher $hookDispatcher;
    /** @var OutputInterface */
    private $output;
    /** @var \Psr\Http\Message\ServerRequestInterface|null Url trait 依赖的当前请求 */
    private $request;

    public function __construct(
        ContainerInterface $container,
        Redis $redis,
        FormSchemaServiceInterface $schema,
        FormQueryServiceInterface $query,
        TableHookDispatcher $hookDispatcher,
        OutputInterface $output,
    ) {
        $this->container = $container;
        $this->redis = $redis;
        $this->schema = $schema;
        $this->query = $query;
        $this->hookDispatcher = $hookDispatcher;
        $this->output = $output;
        // Url trait 依赖 $this->request，运行时从容器解析当前请求
        try {
            $this->request = $container->get(\Psr\Http\Message\ServerRequestInterface::class);
        } catch (\Throwable $e) {
            $this->request = null;
        }
    }

    /**
     * 同步中间件处理后的 request（含 csrfToken 等 attributes）
     */
    public function setRequest(\Psr\Http\Message\ServerRequestInterface $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function export(array $param): OutputInterface
    {
        if (empty($param['fid'])) {
            return $this->output->withCode(21001);
        }
        $form = $this->schema->getForm((int)$param['fid']);
        $hook = $this->hookDispatcher->forForm($form);

        $dataListParam = $param;
        $dataListParam['by'] = 'desc';
        $dataListParam['pagesize'] = $param['pagesize'] ?? 1000;
        $dataListParam['fields'] = '*';
        $result = $this->query->list($dataListParam);
        $data = $result->getData();
        foreach ($data['list'] as $k => $v) {
            foreach ($v as $key => $val) {
                $format = $val && is_numeric($val) && strlen((string)$val) < 10 ? preg_replace('[\d]', '0', (string)$val) : '@';
                $v[$key . '_format'] = 'vnd.ms-excel.numberformat:' . $format . ';height:30px;';
            }
            $data['list'][$k] = $v;
        }
        $result = $result->withData($data);

        $condition = ['formid' => $param['fid'], 'available' => 1, 'isexport' => 1];
        $rs = $hook->dataExportBefore($condition, $result);
        if (!$this->hookOk($rs, $code, $msg)) {
            return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
        }

        $fieldList = $this->schema->getFields($form->id, $condition)->toRawArray();
        $style = 'height:30px;font-weight:bold;background-color:#f6f6f6;text-align:center;';
        $heads = [];
        $heads['id'] = ['title' => '序号', 'datatype' => 'int', 'style' => $style];
        if ($form->cpCheck) {
            $heads['ischeck'] = ['title' => '审核状态', 'datatype' => 'radio', 'style' => $style];
        }
        foreach ($fieldList as $v) {
            $v['style'] = $style;
            $heads[$v['identifier']] = $v;
        }
        $heads['createtime'] = ['title' => '创建时间', 'datatype' => 'date', 'style' => $style];
        $result = $result->withData(['heads' => $heads, 'form' => $form->raw]);

        $rs = $hook->dataExportAfter($result);
        if (!$this->hookOk($rs, $code, $msg)) {
            return $this->output->withCode($code, $msg ? ['msg' => $msg] : []);
        }
        return $this->exportData($result);
    }

    public function streamDownload(string $filepath): OutputInterface
    {
        if (!is_file($filepath)) {
            return $this->output->withCode(21001);
        }
        $response = $this->container->get(\Psr\Http\Message\ResponseInterface::class);
        $filename = basename($filepath);

        // 流式响应，避免大文件 OOM
        $stream = new \Slim\Psr7\Stream(fopen($filepath, 'rb'));
        $response = $response
            ->withHeader('Content-Type', 'application/vnd.ms-excel')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)filesize($filepath))
            ->withBody($stream);

        return $this->output->withCode(200)->withData(['response' => $response]);
    }

    /**
     * 内部：实际生成文件并返回下载进度
     */
    private function exportData(OutputInterface $output): OutputInterface
    {
        $data = $output->getData();
        $filename = md5(serialize($data['where'])) . '.xls';
        $dirname = 'tmpExport/';
        $tmpPath = CSDATA . $dirname;
        File::mkdir($tmpPath);
        $filepath = $tmpPath . $filename;
        $heads = &$data['heads'];

        $start = ($data['page'] - 1) * $data['pagesize'];
        $end = min($start + $data['pagesize'], $data['count']);
        $text = '总数' . $data['count'] . '条,数据处理中第' . $start . '--' . $end . '条,请稍后......';
        if ($data['page'] == 1) {
            is_file($filepath) && unlink($filepath);
            $handle = opendir($tmpPath);
            while (false !== ($resource = readdir($handle))) {
                if (!in_array(strtolower($resource), ['.', '..'])) {
                    $time = filemtime($tmpPath . $resource);
                    if ($time + 3600 < TIMESTAMP) {
                        is_file($tmpPath . $resource) && unlink($tmpPath . $resource);
                    }
                }
            }
            closedir($handle);

            $title = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="expires" content="Mon, 06 Jan 1999 00:00:01 GMT">
<meta http-equiv=Content-Type content="text/html; charset=utf-8">
<!--[if gte mso 9]><xml>
<x:ExcelWorkbook>
<x:ExcelWorksheets>
<x:ExcelWorksheet>
<x:Name>' . $data['form']['name'] . '</x:Name>
<x:WorksheetOptions>
<x:DisplayGridlines/>
</x:WorksheetOptions>
</x:ExcelWorksheet>
</x:ExcelWorksheets>
</x:ExcelWorkbook>
</xml><![endif]-->
</head>';
            $title .= '<table border="1" cellspacing="0" cellpadding="0"><tr>';
            foreach ($heads as $v) {
                $title .= '<td style="' . ($v['style'] ?? '') . '">' . $v['title'] . '</td>';
            }
            $title .= "</tr>\n";
            file_put_contents($filepath, $title, FILE_APPEND);
        }
        $item = '';
        if (!empty($data['list'])) {
            foreach ($data['list'] as $info) {
                $item .= "<tr>\n";
                foreach ($heads as $k1 => $v1) {
                    if (!empty($info['_' . $k1])) {
                        if (is_array($info['_' . $k1])) {
                            $val = json_encode($info['_' . $k1]);
                        } else {
                            $val = $info['_' . $k1];
                        }
                        $format = $info['_' . $k1 . '_format'] ?? '@';
                    } else {
                        $val = $info[$k1] ?? '';
                        if (empty($val) && in_array($v1['datatype'], ['date', 'datetime'])) {
                            $val = '';
                        }
                        $format = $info[$k1 . '_format'] ?? '@';
                    }
                    $item .= "<td style='" . $format . "'>" . $val . "</td>";
                }
                $item .= "</tr>";
            }
        }
        $down = '';
        if ($data['page'] + 1 >= $data['maxpages']) {
            $down = '&down=1';
            $text = '下载完成';
        }
        if ($data['page'] >= $data['maxpages']) {
            $item .= '</table>';
        }
        file_put_contents($filepath, $item, FILE_APPEND);
        $data['page']++;
        $url = $this->url('&page=' . $data['page'] . $down);
        return $this->output->withData(['file' => $filepath, 'text' => $text])->withReferer($url);
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
}
