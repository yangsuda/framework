<?php
/**
 * 数据库内容相关读写操作
 * @author zhucy
 */
declare(strict_types=1);

namespace SlimCMS\Abstracts;

use App\Core\Forms;
use SlimCMS\Error\TextException;
use SlimCMS\Interfaces\OutputInterface;

abstract class RepositoryAbstract extends BaseAbstract
{
    protected static function getData(array $param): array
    {
        $data = [];
        $formid = self::getFid();
        $list = self::t('forms_fields')->withWhere(['formid' => $formid, 'available' => 1])->fetchList('identifier,datatype,rules');
        foreach ($list as $v) {
            switch ($v['datatype']) {
                case 'radio':
                case 'select':
                case 'month':
                case 'date':
                case 'datetime':
                case 'int':
                case 'stepselect':
                    isset($param[$v['identifier']]) && $data[$v['identifier']] = (int)$param[$v['identifier']];
                    break;
                case 'float':
                case 'tel':
                case 'price':
                    isset($param[$v['identifier']]) && $data[$v['identifier']] = (float)$param[$v['identifier']];
                    break;
                default:
                    isset($param[$v['identifier']]) && $data[$v['identifier']] = (string)$param[$v['identifier']];
                    break;
            }
        }
        return $data;
    }

    /**
     * 添加
     * @param array $param
     * @return OutputInterface
     */
    public static function add(array $param): OutputInterface
    {
        $data = static::getData($param);
        if (empty($data)) {
            return self::$output->withCode(21020);
        }
        return Forms::dataSave(self::getFid(), [], $data);
    }

    /**
     * 修改
     * @param int $id
     * @param array $param
     * @return OutputInterface
     */
    public static function edit(int $id, array $param): OutputInterface
    {
        $data = static::getData($param);
        if (empty($data)) {
            return self::$output->withCode(21020);
        }
        return Forms::dataSave(self::getFid(), $id, $data);
    }

    /**
     * 删除
     * @param int $id
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public static function delete(int $id): OutputInterface
    {
        if (empty($id)) {
            return self::$output->withCode(21003);
        }
        return Forms::dataDel(static::getFid(), [$id]);
    }

    /**
     * 详细
     * @param int $id 信息ID
     * @param string $fields 读取字段，多个字段用,隔开
     * @param string $extraFields 自定义返回值对应的key，多个key用,隔开
     * @param array $param 其它自定义参数
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public static function detail(int $id, string $fields, string $extraFields = '', array $param = []): OutputInterface
    {
        if (empty($id) || empty($fields)) {
            return self::$output->withCode(21002);
        }
        $res = Forms::dataView(static::getFid(), $id, $fields);
        if ($res->getCode() != 200) {
            return $res;
        }
        $data = (array)$res->getData()['row'];
        static::reprocess($data, $extraFields, $param);
        //自定义方法处理
        if (!empty($param['callback']) && $extraFields && method_exists($param['callback'], 'reprocess') && is_callable([$param['callback'], 'reprocess'])) {
            $callback = $param['callback'] . '::reprocess';
            $callback($data, $extraFields, $param);
        }
        return self::$output->withCode(200)->withData($data);
    }

    /**
     * 生成筛选条件
     * @param array $param
     * @return array
     */
    protected static function condition(array $param): array
    {
        $where = !empty($param['where']) ? $param['where'] : [];
        if (!empty($param['start']) && !is_numeric($param['start'])) {
            $param['start'] = strtotime($param['start']);
        }
        if (!empty($param['end']) && !is_numeric($param['end'])) {
            if (!strpos($param['end'], ':')) {
                $param['end'] .= ' 23:59:59';
            }
            $param['end'] = strtotime($param['end']);
        }
        $field = $param['dateField'] ?? 'createtime';
        !empty($param['start']) && $where[] = self::t()->field($field, $param['start'], '>=');
        !empty($param['end']) && $where[] = self::t()->field($field, $param['end'], '<=');
        //自定义方法处理
        if (!empty($param['callback']) && method_exists($param['callback'], 'condition') && is_callable([$param['callback'], 'condition'])) {
            $callback = $param['callback'] . '::condition';
            $callback($where, $param);
        }
        return $where;
    }

    /**
     * 列表
     * @param array $param
     * @return OutputInterface
     */
    public static function list(array $param = []): OutputInterface
    {
        $data = static::_list($param);
        //额外输出参数
        $other = [];
        if (!empty($param['callback']) && method_exists($param['callback'], 'listExtra') && is_callable([$param['callback'], 'listExtra'])) {
            $callback = $param['callback'] . '::listExtra';
            $other = $callback($param, $data);
        }
        return static::pageListOutput($data, $other);
    }

    /**
     * 统一翻页列表输出
     * @param array $result
     * @return array
     */
    protected static function pageListOutput(array $result, array $other = []): OutputInterface
    {
        $data = [
            'list' => aval($result, 'list'),
            'count' => aval($result, 'count'),
            'maxpages' => aval($result, 'maxpages', 0),
            'page' => aval($result, 'page', 1),
            'pagesize' => aval($result, 'pagesize', 30)
        ];
        $other && $data += $other;
        return self::$output->withCode(200)->withData($data);
    }

    protected static function _list(array $param = []): array
    {
        $fields = !empty($param['fields']) ? $param['fields'] : 'id,createtime';
        $params = [
            'fid' => static::getFid(),
            'page' => aval($param, 'page', 1),
            'pagesize' => aval($param, 'pagesize', 30),
            'fields' => $fields,
            'order' => aval($param, 'order'),
            'by' => aval($param, 'by') ?: 'desc',
            'noinput' => true,
            'orderForce' => aval($param, 'orderForce', true),
            'joins' => aval($param, 'joins'),
            'joinFields' => aval($param, 'joinFields'),
            'indexField' => aval($param, 'indexField'),
            'extendFormName' => aval($param, 'extendFormName'),
        ];
        $params['where'] = static::condition($param);
        $res = Forms::dataList($params);
        $data = $res->getData();
        if (!empty($param['extraFields'])) {
            foreach ($data['list'] as &$v) {
                static::reprocess($v, $param['extraFields']);
                //自定义方法处理
                if (!empty($param['callback']) && !empty($param['extraFields']) && method_exists($param['callback'], 'reprocess') && is_callable([$param['callback'], 'reprocess'])) {
                    $callback = $param['callback'] . '::reprocess';
                    $callback($v, $param['extraFields'], $param);
                }
            }
        }
        return $data;
    }

    /**
     * 数据二次处理
     * @param array $data
     * @param string $fields 额外字段
     * @param array $param 副表字段
     * @return array
     */
    protected static function reprocess(&$data, $fields = '', $param = [])
    {
        $fieldArr = $fields ? explode(',', $fields) : [];
    }


    /**
     * 表单名称
     * @return string
     */
    public static function tableName(): string
    {
        return strtolower(substr(strrchr(get_called_class(), '\\'), 1));
    }

    /**
     * 获取对应表的ID
     * @return int
     * @throws TextException
     */
    public static function getFid(): int
    {
        $table = self::tableName();
        $id = (int)self::t('forms')->withWhere(['table' => $table])->fetch('id');
        if (empty($id)) {
            throw new TextException(21039);
        }
        return $id;
    }

    public static function batchDelete(array $param): int
    {
        if (empty($param)) {
            throw new TextException(21010);
        }
        $where = static::condition($param);
        $table = self::tableName();
        return self::t($table)->withWhere($where)->delete();
    }

    public static function batchUpdate(array $param, array $value): int
    {
        if (empty($value)) {
            throw new TextException(21010);
        }
        $where = static::condition($param);
        $table = self::tableName();
        return self::t($table)->withWhere($where)->update($value);
    }

    /**
     * 数量统计
     * @param array $param
     * @return int
     * @throws TextException
     */
    public static function count(array $param = [], string $fields = '*', int $cacheTime = 0): int
    {
        $where = static::condition($param);
        $table = self::tableName();
        return self::t($table)->withWhere($where)->count($fields, $cacheTime);
    }

    /**
     * 累加统计
     * @param string $field
     * @param array $param
     * @return float
     * @throws TextException
     */
    public static function sum(string $field, array $param = []): float
    {
        if (empty($field)) {
            throw new TextException(21010);
        }
        $where = static::condition($param);
        $table = self::tableName();
        return (float)self::t($table)->withWhere($where)->sum($field);
    }

    public static function fetchColumn(string $field, string $func, array $param = [])
    {
        if (empty($field) || empty($func)) {
            throw new TextException(21010);
        }
        $where = static::condition($param);
        $table = self::tableName();
        return self::t($table)->withWhere($where)->fetchColumn($field, $func);
    }


    public static function fetch(string $field, array $param, int $cacheTime = 0)
    {
        if (empty($field) || empty($param)) {
            throw new TextException(21010);
        }
        $where = static::condition($param);
        $table = self::tableName();
        return self::t($table)->withWhere($where)->fetch($field, $cacheTime);
    }

    public static function fetchList(string $field, array $param = [], string $indexField = '', int $cacheTime = 0): array
    {
        if (empty($field)) {
            throw new TextException(21010);
        }
        $where = static::condition($param);
        $table = self::tableName();
        return self::t($table)->withWhere($where)->fetchList($field, $indexField, $cacheTime);
    }

    /**
     * 数据有效性检测
     * @param array $data 数据
     * @param int $id 要编辑的信息ID(判断唯一性时用到)
     * @return array
     * @throws TextException
     */
    public static function validCheck(array $data, int $id = 0): array
    {
        return Forms::validCheck(self::getFid(), $data, $id);
    }
}
