<?php
declare(strict_types=1);

namespace SlimCMS\Interfaces;


use JsonSerializable;
use Slim\App;

interface OutputInterface extends JsonSerializable
{
    /**
     * 当尝试以调用函数的方式调用一个对象时，此方法会被自动调用
     * @param App $app
     * @return mixed
     */
    public function __invoke(App $app);

    public function __set($name, $value);

    public function __get($name);

    /**
     * 提示信息
     * @return array
     */
    public function prompts(): array;

    /**
     * 返回提示文字
     * @return string
     */
    public function getMsg(): string;

    /**
     * 返回提示代码
     * @return int
     */
    public function getCode(): int;

    /**
     * 设置返回提示代码
     * @param int $code
     * @param array $param
     * @return OutputInterface
     */
    public function withCode(int $code, $param = []): self;

    /**
     * 返回输出数据
     * @return int
     */
    public function getData(): array;

    /**
     * 设置返回输出数据
     * @param int $data
     * @return OutputInterface
     */
    public function withData(array $data): self;

    /**
     * 获取模板
     * @return string
     */
    public function getTemplate(): string;

    /**
     * 设置解析的模板
     * @param string $template
     * @return OutputInterface
     */
    public function withTemplate(string $template): self;

    /**
     * 返回跳转URL
     * @return string
     */
    public function getReferer(): string;

    /**
     * 设置跳转URL
     * @param string $url
     * @return OutputInterface
     */
    public function withReferer(string $url): self;

    /**
     * 解析模板
     * @return mixed
     */
    public function analysisTemplate(): string;

    /**
     * 对象转成json时处理方法
     * @return array
     */
    public function jsonSerialize(): array;
}
