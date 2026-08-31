<?php
/**
 * 表单服务总线（编排层）
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core\Form;

use SlimCMS\Interfaces\OutputInterface;

/**
 * 表单服务总线
 *
 * 替换原 Forms 上帝类，对外暴露与旧 Forms 同名的方法签名，
 * 内部委托给 5 个子服务，仅做"调用顺序 + formVerify + Output 装配"。
 *
 * 控制器与 Repository 通过此总线或子服务接口访问，二选一。
 */
final class FormServiceBus
{

    private FormQueryServiceInterface $query;
    private FormWriteServiceInterface $write;
    private FormViewRendererInterface $renderer;
    private FormExportServiceInterface $export;
    private FormSchemaServiceInterface $schema;
    private TableHookDispatcher $hookDispatcher;
    private OrderValidator $orderValidator;
    /** @var OutputInterface */
    private $output;
    private \Psr\Container\ContainerInterface $container;

    public function __construct(
        FormQueryServiceInterface $query,
        FormWriteServiceInterface $write,
        FormViewRendererInterface $renderer,
        FormExportServiceInterface $export,
        FormSchemaServiceInterface $schema,
        TableHookDispatcher $hookDispatcher,
        OrderValidator $orderValidator,
        OutputInterface $output,
        \Psr\Container\ContainerInterface $container,
    ) {
        $this->query = $query;
        $this->write = $write;
        $this->renderer = $renderer;
        $this->export = $export;
        $this->schema = $schema;
        $this->hookDispatcher = $hookDispatcher;
        $this->orderValidator = $orderValidator;
        $this->output = $output;
        $this->container = $container;
    }

    /**
     * 起始输出契约（formVerify 后链式调用）
     *
     * 兼容旧 Forms 的调用风格：$this->forms()->formVerify($ccode)->dataSave(...)
     * formVerify 修改 output 状态后返回 $this。
     */
    public function formVerify(string $ccode = null): self
    {
        $session = $this->container->get(\SlimCMS\Core\Session::class);
        if (isset($ccode) && $session->get('VerifyCode') != strtolower($ccode)) {
            $session->delete('VerifyCode');
            $this->output = $this->output->withCode(24023);
        }
        return $this;
    }

    /**
     * 同步中间件处理后的 request 给所有子服务
     *
     * 容器中的 ServerRequestInterface 是从 globals 创建的原始请求，
     * 不含中间件管道添加的 attributes（如 csrfToken）。
     * 控制器通过 RouteAction::setRequest($request) 持有最新 request，
     * 调用此方法将最新 request 传播到所有子服务。
     */
    public function setRequest(\Psr\Http\Message\ServerRequestInterface $request): self
    {
        $this->query->setRequest($request);
        $this->write->setRequest($request);
        $this->renderer->setRequest($request);
        $this->export->setRequest($request);
        return $this;
    }

    /**
     * 返回 Output，结束链式调用
     */
    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    // ============ 查询 ============

    public function dataList(array $param): OutputInterface
    {
        return $this->query->list($param);
    }

    public function dataView(int $fid, int $id, string $fields = '*', int $cacheTime = 0, array $options = []): OutputInterface
    {
        return $this->query->view($fid, $id, $fields, $cacheTime, $options);
    }

    public function dataCount(array $param): OutputInterface
    {
        return $this->query->count($param);
    }

    public function enumSubids(string $egroup, int $evalue = 0): OutputInterface
    {
        return $this->query->enumSubids($egroup, $evalue);
    }

    // ============ 写入 ============

    public function dataSave(int $fid, $row = [], array $data = [], array $options = []): OutputInterface
    {
        return $this->write->save($fid, $row, $data, $options);
    }

    public function dataCheck(int $fid, array $ids, int $ischeck = 1, array $options = []): OutputInterface
    {
        return $this->write->check($fid, $ids, $ischeck, $options);
    }

    public function dataDel(int $fid, array $ids, array $options = []): OutputInterface
    {
        return $this->write->delete($fid, $ids, $options);
    }

    public function delAttachment(array $fields, array $data): OutputInterface
    {
        return $this->write->deleteAttachments($fields, $data);
    }

    public function validCheck(int $fid, array $data, int $id = 0): array
    {
        return $this->write->validCheck($fid, $data, $id);
    }

    // ============ 视图 ============

    public function dataFormHtml(int $fid, $row = [], array $options = []): OutputInterface
    {
        return $this->renderer->renderFormHtml($fid, $row, $options);
    }

    public function listFields(int $fid, int $limit = 30, string $fieldName = 'inlistcp'): OutputInterface
    {
        return $this->renderer->listFields($fid, $limit, $fieldName);
    }

    public function searchFields(int $fid, string $fields = ''): OutputInterface
    {
        return $this->renderer->searchFields($fid, $fields);
    }

    public function orderFields(int $fid): OutputInterface
    {
        return $this->renderer->orderFields($fid);
    }

    public function allValidFields(int $fid): OutputInterface
    {
        return $this->renderer->allValidFields($fid);
    }

    // ============ 导出 ============

    public function dataExport(array $param): OutputInterface
    {
        return $this->export->export($param);
    }

    public function exportDownload(string $filepath): OutputInterface
    {
        return $this->export->streamDownload($filepath);
    }

    // ============ Schema（直通） ============

    public function tableDataRules(array $rules): array
    {
        return $this->schema->resolveDynamicRules($rules);
    }

    public function serializeImgs(array $imgs): string
    {
        return \SlimCMS\Core\Form\ImgsSerializer::serialize($imgs);
    }

    public function unserializeImgs(string $imgs, int $width = 1000, int $height = 1000): array
    {
        $uploader = $this->container->get(\SlimCMS\Interfaces\UploadInterface::class);
        return \SlimCMS\Core\Form\ImgsSerializer::unserialize($imgs, $width, $height, fn($img, $w, $h) => $uploader->copyImage($img, $w, $h));
    }

    public function exchangeFieldValue(array $fields, array $row): array
    {
        return \SlimCMS\Core\Form\FormValueTransformer::exchange($fields, $row, $this->container);
    }
}
