<?php
/**
 * 表单处理类
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core;

use SlimCMS\Core\Ueditor;
use SlimCMS\Abstracts\BaseAbstract;
use SlimCMS\Error\TextException;
use SlimCMS\Helper\Crypt;
use SlimCMS\Helper\File;
use SlimCMS\Helper\Ipdata;
use SlimCMS\Helper\Str;
use SlimCMS\Helper\Time;
use SlimCMS\Interfaces\OutputInterface;
use SlimCMS\Interfaces\UploadInterface;

class Forms extends BaseAbstract
{
    use \SlimCMS\Traits\table;
    use \SlimCMS\Traits\Form;

    /**
     * 某表单详细
     * @param int $fid
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function formView(int $fid): OutputInterface
    {
        static $vals = [];
        if (empty($vals[$fid])) {
            $form = $this->t('forms')->withWhere($fid)->fetch();
            if (empty($form)) {
                return $this->output->withCode(22006);
            }
            $vals[$fid] = ['form' => $form, 'fid' => $fid];
        }
        return $this->output->withCode(200)->withData($vals[$fid]);
    }

    /**
     * 数据审核操作
     * @param int $fid
     * @param array $ids
     * @param int $ischeck
     * @param array $options 方便接收外部自定义数据
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function dataCheck(int $fid, array $ids, int $ischeck = 1, array $options = []): OutputInterface
    {
        if (empty($fid) || empty($ids)) {
            return $this->output->withCode(21002);
        }
        $ids = array_map('intval', $ids);
        $form = $this->formView($fid)->getData()['form'];
        if (empty($form)) {
            return $this->output->withCode(22006);
        }
        //处理前
        if (is_callable([$this->t($form['table']), 'dataCheckBefore'])) {
            $rs = $this->t($form['table'])->dataCheckBefore($ids, $ischeck, $options);
            if (is_array($rs)) {
                if ($rs['code'] != 200) {
                    return $this->output->withCode($rs['code'], $rs['msg']);
                }
            } else {
                if ($rs != 200) {
                    return $this->output->withCode($rs);
                }
            }
        }
        $this->t($form['table'])->withWhere(['id' => $ids])->update(['ischeck' => $ischeck]);
        //处理后
        if (is_callable([$this->t($form['table']), 'dataCheckAfter'])) {
            $rs = $this->t($form['table'])->dataCheckAfter($ids, $ischeck, $options);
            if ($rs != 200) {
                return $this->output->withCode($rs);
            }
        }
        return $this->output->withCode(200, 21032);
    }

    /**
     * 删除数据
     * @param int $fid
     * @param array $ids
     * @param array $options 方便接收外部自定义数据
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function dataDel(int $fid, array $ids, array $options = []): OutputInterface
    {
        if (empty($fid) || empty($ids)) {
            return $this->output->withCode(21002);
        }
        $ids = array_map('intval', $ids);
        $form = $this->formView($fid)->getData()['form'];
        if (empty($form)) {
            return $this->output->withCode(22006);
        }
        $list = $this->t($form['table'])->withWhere(['id' => $ids])->fetchList();
        if (empty($list)) {
            return $this->output->withCode(21001);
        }
        foreach ($list as $k => $v) {
            if (is_callable([$this->t($form['table']), 'dataDelBefore'])) {
                $rs = $this->t($form['table'])->dataDelBefore($v, $options);
                if ($rs != 200) {
                    return $this->output->withCode($rs);
                }
            }

            aval($form, 'isarchive') == 1 && $this->dataSave(7, '', ['formid' => $fid, 'aid' => $v['id'], 'content' => serialize($v)]);//归档记录

            //判断删除文章附件变量是否开启；
            if ($this->config['isDelAttachment'] == '1') {
                //判断属性；
                $fields = $this->fieldList(['formid' => $fid, 'available' => 1, 'datatype' => ['htmltext', 'imgs', 'img', 'media', 'addon', 'superfile']]);
                if ($fields) {
                    $this->delAttachment($fields, $v);
                }
            }

            //删除相关数据
            if (is_callable([$this->t($form['table']), 'dataDelAfter'])) {
                $rs = $this->t($form['table'])->dataDelAfter($v, $options);
                if ($rs != 200) {
                    return $this->output->withCode($rs);
                }
            }
        }
        $this->t($form['table'])->withWhere(['id' => $ids])->delete();
        //获取额外数据
        if (is_callable([$this->t($form['table']), 'dataDelRealAfter'])) {
            $this->t($form['table'])->dataDelRealAfter($list);
        }
        return $this->output->withCode(200, 21023);
    }

    /**
     * 详细数据
     * @param int $fid
     * @param int $id
     * @param string $fields
     * @param int $cacheTime
     * @param array $options 方便接收外部自定义数据
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function dataView(int $fid, int $id, string $fields = '*', int $cacheTime = 0, array $options = []): OutputInterface
    {
        if (empty($fid) || empty($id)) {
            return $this->output->withCode(21002);
        }
        $cachekey = $this->cacheKey('dataView', $fid, $id);
        $data = $cacheTime > 0 ? $this->redis->get($cachekey) : [];
        if (empty($data)) {
            $form = $this->formView($fid)->getData()['form'];
            if (empty($form)) {
                return $this->output->withCode(22006);
            }
            if (is_callable([$this->t($form['table']), 'dataViewBefore'])) {
                $rs = $this->t($form['table'])->dataViewBefore($id, $options);
                if (is_array($rs)) {
                    if ($rs['code'] != 200) {
                        return $this->output->withCode($rs['code'], ['msg' => $rs['msg']]);
                    }
                } else {
                    if ($rs != 200) {
                        return $this->output->withCode($rs);
                    }
                }
            }
            $data = $this->t($form['table'])->withWhere($id)->fetch($fields);
            if (empty($data)) {
                return $this->output->withCode(21001);
            }
            if (!is_array($data)) {
                $val = [];
                $val[$fields] = $data;
                $data = $val;
            }
            $fields = $this->fieldList(['formid' => $fid, 'available' => 1]);
            $fields && $data = $this->exchangeFieldValue($fields, $data);

            //获取额外数据
            if (is_callable([$this->t($form['table']), 'dataViewAfter'])) {
                $rs = $this->t($form['table'])->dataViewAfter($data, $options);
                if ($rs != 200) {
                    return $this->output->withCode($rs);
                }
            }
            $data = ['row' => $data, 'form' => $form, 'fields' => $fields];
            $cacheTime && $this->redis->set($cachekey, $data, $cacheTime);
        }
        return $this->output->withData($data)->withCode(200);
    }

    /**
     * 数据统计
     * @param array $param
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function dataCount(array $param): OutputInterface
    {
        if (empty($param['fid'])) {
            return $this->output->withCode(21002);
        }
        $arr = [];
        $arr['where'] = [];
        if (empty($param['noinput'])) {
            $arr = $this->searchCondition($param)->getData();
        }
        if (!empty($param['cacheTime'])) {
            $cachekey = $this->cacheKey(__FUNCTION__, $param, $arr['where']);
            $data = $this->redis->get($cachekey);
        }
        if (empty($data)) {
            $form = $this->formView((int)$param['fid'])->getData()['form'];
            if (empty($form)) {
                return $this->output->withCode(22006);
            }

            if (is_callable([$this->t($form['table']), 'dataCountBefore'])) {
                $rs = $this->t($form['table'])->dataCountBefore($param);
                if (is_array($rs)) {
                    if ($rs['code'] != 200) {
                        return $this->output->withCode($rs['code'], ['msg' => $rs['msg']]);
                    }
                } else {
                    if ($rs != 200) {
                        return $this->output->withCode($rs);
                    }
                }
            }

            $where = !empty($param['where']) ? array_merge($param['where'], $arr['where']) : $arr['where'];
            $countFields = (string)aval($param, 'countFields');
            $data = [];
            $data['count'] = $this->t($form['table'])->withWhere($where)->count($countFields);
            $data['form'] = $form;
            $data['fid'] = $param['fid'];
            //缓存保存
            !empty($param['cacheTime']) && $this->redis->set($cachekey, $data, $param['cacheTime']);
        }
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * 获取字段管理对象
     * @return Table
     */
    protected function formFields(): Table
    {
        return $this->t('forms_fields');
    }

    /**
     * 列表数据
     * @param array $param
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function dataList(array $param): OutputInterface
    {
        if (empty($param['fid'])) {
            return $this->output->withCode(21002);
        }
        $res = $this->formView((int)$param['fid'])->getData();
        $form = aval($res, 'form');
        if (empty($form)) {
            return $this->output->withCode(22006);
        }
        if (is_callable([$this->t($form['table']), 'dataListInit'])) {
            $rs = $this->t($form['table'])->dataListInit($param);
            if (is_array($rs)) {
                if ($rs['code'] != 200) {
                    return $this->output->withCode($rs['code'], ['msg' => $rs['msg']]);
                }
            } else {
                if ($rs != 200) {
                    return $this->output->withCode($rs);
                }
            }
        }

        $param['currenturl'] = $this->url();
        $param['get'] = [];
        $arr = [];
        $arr['where'] = [];
        if (empty($param['noinput'])) {
            $arr = $this->searchCondition($param)->getData();
            $param['get'] = $arr['get'];
            $param['currenturl'] .= $arr['currentUrl'];
        }
        if (!empty($param['cacheTime'])) {
            $para = $param;
            unset($para['currenturl']);
            $cachekey = $this->cacheKey(__FUNCTION__, $para, $arr['where']);
            $data = $this->redis->get($cachekey);
        }
        if (empty($data)) {
            if (aval($form, 'cpcheck') == 1) {
                $ischeck = aval($param, 'ischeck');
                if ($ischeck) {
                    $param['get']['ischeck'] = $ischeck;
                    $where = [];
                    $where['ischeck'] = $ischeck;
                    $param['where'] = !empty($param['where']) ? array_merge((array)$param['where'], $where) : $where;
                    empty($param['noinput']) && $param['currenturl'] .= '&ischeck=' . $ischeck;
                }
            }

            if (empty($param['noinput'])) {
                //根据ID筛选
                $id = aval($param, 'id');
                $id = $id && strpos((string)$id, '`') ? array_map('intval', explode('`', $id)) : (int)$id;
                if ($id) {
                    $where = [];
                    $where['id'] = $id;
                    $param['where'] = !empty($param['where']) ? array_merge((array)$param['where'], $where) : $where;
                    empty($param['noinput']) && $param['currenturl'] .= '&id=' . (is_array($id) ? implode('`', $id) : $id);
                }
            }

            if (empty($param['fields'])) {
                $fields = $this->formFields()
                    ->withWhere(['formid' => $param['fid'], 'available' => 1, 'inlistcp' => 1])
                    ->onefieldList('identifier', 60);
                $fields[] = 'createtime';
                $fields[] = 'ischeck';
                $fields[] = 'id';
                $param['fields'] = implode(',', $fields);
            }
            if (!empty($param['joinFields'])) {
                $param['fields'] = 'main.' . str_replace(',', ',main.', $param['fields']) . ',' . $param['joinFields'];
            }

            $param['where'] = !empty($param['where']) ? array_merge($arr['where'], $param['where']) : $arr['where'];

            if (is_callable([$this->t($form['table']), 'dataListBefore'])) {
                $rs = $this->t($form['table'])->dataListBefore($param);
                if (is_array($rs)) {
                    if ($rs['code'] != 200) {
                        return $this->output->withCode($rs['code'], ['msg' => $rs['msg']]);
                    }
                } else {
                    if ($rs != 200) {
                        return $this->output->withCode($rs);
                    }
                }
            }

            $order = (string)aval($param, 'order');
            $orderForce = (bool)aval($param, 'orderForce');
            $order = $this->validOrder($param['fid'], $order, $orderForce);
            $by = (string)aval($param, 'by', 'desc');
            $page = (int)aval($param, 'page', 1);
            $fields = (string)aval($param, 'fields');
            $pagesize = (int)aval($param, 'pagesize', 30);
            $indexField = (string)aval($param, 'indexField');
            $joins = (array)aval($param, 'joins');
            $groupby = (string)aval($param, 'groupby', '');
            $data = $this->t($form['table'], aval($param, 'extendFormName'))
                ->withJoin($joins)
                ->withWhere($param['where'])
                ->withGroupby($groupby)
                ->withOrderby($order, $by)
                ->pageList($page, $fields, $pagesize, 0, $indexField);
            $fields = $this->fieldList(['formid' => $param['fid'], 'available' => 1]);
            foreach ($data['list'] as &$v) {
                isset($v['id']) && $v['id'] = (int)$v['id'];
                isset($v['createtime']) && $v['createtime'] = (int)$v['createtime'];
                isset($v['ischeck']) && $v['ischeck'] = (int)$v['ischeck'];
                isset($v['ischeck']) && $v['_ischeck'] = $v['ischeck'] == 1 ? '已审核' : '未审核';
                $fields && $v = $this->exchangeFieldValue($fields, $v);
            }
            if (!empty($arr['tags'])) {
                $data['tags'] = $arr['tags'];
            }
            $data['form'] = $form;
            $data['fid'] = $param['fid'];
            $data['order'] = $order;
            $data['by'] = $by;
            $data['currenturl'] = $param['currenturl'];
            $data['get'] = $param['get'];
            $data['where'] = $param['where'];

            if (is_callable([$this->t($form['table']), 'dataListAfter'])) {
                $rs = $this->t($form['table'])->dataListAfter($data, $param);
                if ($rs != 200) {
                    return $this->output->withCode($rs);
                }
            }

            //缓存保存
            !empty($param['cacheTime']) && !empty($data['list']) && $this->redis->set($cachekey, $data, $param['cacheTime']);
        }
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * 获取生成的表单HTML
     * @param int $fid
     * @param array $row
     * @param array $options
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function dataFormHtml(int $fid, $row = [], array $options = []): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        if ($row && is_numeric($row)) {
            $res = $this->dataView($fid, $row);
            if ($res->getCode() != 200) {
                return $res;
            }
            $val = $res->getData();
            $row = $val['row'];
            $form = $val['form'];
        } else {
            $row = [];
            $form = $this->formView($fid)->getData()['form'];
            if (empty($form)) {
                return $this->output->withCode(22006);
            }
        }

        $condition = [];
        $condition['formid'] = $fid;
        $condition['available'] = 1;
        if (aval($options, 'infront') === true) {
            $condition['infront'] = 1;
        }
        $fields = (array)$this->fieldList($condition);
        if (empty($row)) {
            $row = [];
            foreach ($fields as $k => $v) {
                $row[$v['identifier']] = $this->request()->input($v['identifier']);
            }
        }

        if (is_callable([$this->t($form['table']), 'getFormHtmlBefore'])) {
            $rs = $this->t($form['table'])->getFormHtmlBefore($fields, $row, $form, $options);
            if (is_array($rs)) {
                if ($rs['code'] != 200) {
                    return $this->output->withCode($rs['code'], ['msg' => $rs['msg']]);
                }
            } else {
                if ($rs != 200) {
                    return $this->output->withCode($rs);
                }
            }
        }

        $cachekey = $this->cacheKey(__FUNCTION__, $fid, $options);
        $data = $this->redis->get($cachekey);
        if (empty($data) || $row) {
            $fieldshtml = $this->formHtml($fid, $fields, $row, $options);

            if (is_callable([$this->t($form['table']), 'getFormHtmlAfter'])) {
                $rs = $this->t($form['table'])->getFormHtmlAfter($fieldshtml, $fields, $row, $options);
                if ($rs != 200) {
                    return $this->output->withCode($rs);
                }
            }

            $data = ['fields' => $fields, 'fieldshtml' => $fieldshtml, 'data' => $row, 'form' => $form, 'fid' => $fid];
            empty($row) && !empty($options['cacheTime']) && $this->redis->set($cachekey, $data, $options['cacheTime']);
        }
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * 保存自定义表单的数据
     * @param int $fid 自定义表单对应的ID
     * @param array $row 原来的数据
     * @param array $data 要添加或修改的数据
     * @param array $options 方便接收外部自定义数据
     */
    public function dataSave(int $fid, $row = [], array $data = [], array $options = []): OutputInterface
    {
        //编辑数据
        if ($this->output->getCode() != 200) {
            return $this->output;
        }
        if ($row && is_numeric($row)) {
            $res = $this->dataView($fid, (int)$row);
            if ($res->getCode() != 200) {
                return $res;
            }
            $val = $res->getData();
            $row = $val['row'];
            $form = $val['form'];
            $fields = $val['fields'];
        } else {
            $row = $row ?: [];
            $formData = $this->formView($fid)->getData();
            if (empty($formData['form'])) {
                return $this->output->withCode(22006);
            }
            $form = $formData['form'];
            $fields = $this->fieldList(['formid' => $fid, 'available' => 1]);;
        }
        if (is_callable([$this->t($form['table']), 'dataSaveInit'])) {
            $rs = $this->t($form['table'])->dataSaveInit($fields, $data, $row, $options);
            if ($rs != 200) {
                return $this->output->withCode($rs);
            }
        }
        $res = $this->requiredCheck($fid, $row, $data);
        if ($res->getCode() != 200) {
            return $res;
        }

        $data = $data ?: $this->getFormValue($fields, $row);

        //判断是否唯一
        $uniques = $this->fieldList(['formid' => $fid, 'unique' => 1, 'available' => 1]);
        foreach ($uniques as $v) {
            $exist_id = aval($data, $v['identifier']) ?
                $this->t($form['table'])->withWhere([$v['identifier'] => $data[$v['identifier']]])->fetch('id')
                : '';
            if ($exist_id && (empty($row['id']) || $exist_id != aval($row, 'id'))) {
                return $this->output->withCode(22004);
            }
        }

        if (is_callable([$this->t($form['table']), 'dataSaveBefore'])) {
            $rs = $this->t($form['table'])->dataSaveBefore($data, $row, $options);
            if (is_array($rs)) {
                if ($rs['code'] != 200) {
                    return $this->output->withCode($rs['code'], ['msg' => $rs['msg']]);
                }
            } else {
                if ($rs != 200) {
                    return $this->output->withCode($rs);
                }
            }
        }

        if (!empty($row['id'])) {
            $this->t($form['table'])->withWhere($row['id'])->update($data);
            $data['id'] = $row['id'];
            $data['mngtype'] = 'edit';
            $this->redis->del($this->cacheKey('dataView', $fid, $row['id']));
        } else {
            empty($data['createtime']) && $data['createtime'] = TIMESTAMP;
            empty($data['ip']) && $data['ip'] = Ipdata::getip();
            $data['id'] = $this->t($form['table'])->insert($data, true);
            $data['mngtype'] = 'add';
            $row = $data;
        }

        if (is_callable([$this->t($form['table']), 'dataSaveAfter'])) {
            $rs = $this->t($form['table'])->dataSaveAfter($data, $row, $options);
            if ($rs != 200) {
                return $this->output->withCode($rs);
            }
        }
        return $this->output->withCode(200, 21018)->withData(['id' => $data['id']]);
    }

    /**
     * 必填检测
     * @param int $fid
     * @param array $row
     * @param array $data
     * @return OutputInterface
     */
    protected function requiredCheck(int $fid, array $row = [], array $data = []): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        $requireds = $this->fieldList(['formid' => $fid, 'required' => 1, 'available' => 1]);
        foreach ($requireds as $v) {
            $msg = $v['errormsg'] ? $v['errormsg'] : $v['title'];
            $val = aval($data, $v['identifier']) ?: $this->request()->input($v['identifier']);
            $val = $val ?: (!empty($v['egroup']) ? (int)$this->request()->input($v['egroup']) : '');
            if (empty($row[$v['identifier']]) && !$val && !in_array($v['datatype'], ['img', 'media', 'addon', 'addons', 'imgs', 'superfile'])) {
                return $this->output->withCode(21008, '“' . $msg . '”不能为空！');
            }
        }
        return $this->output->withCode(200);
    }

    /**
     * 排序字段有效性检测
     * @param int $fid
     * @param string $order
     * @param bool $force
     * @return string
     * @throws \SlimCMS\Error\TextException
     */
    protected function validOrder(int $fid, string $order = '', bool $force = false): string
    {
        if ($force === true) {
            return $order;
        }
        if (empty($order)) {
            $row = $this->formFields()->withWhere(['formid' => $fid, 'available' => 1, 'defaultorder' => [1, 2]])->fetch();
            $order = 'main.id';
            if ($row) {
                $by = $row['defaultorder'] == 1 ? 'desc' : 'asc';
                $order = 'main.' . $row['identifier'] . ' ' . $by . ',' . $order;
            }
            return $order;
        }
        if ($order == 'rand&#040;&#041;' || $order == 'rand()') {
            return 'rand()';
        }
        $fields = (array)$order;
        if (strpos((string)$order, ',')) {
            $fields = explode(',', str_replace([' desc', ' asc'], '', $order));
        }

        $valid = true;
        foreach ($fields as $v) {
            $v = trim($v);
            if ($v == 'id') {
                continue;
            }
            $where = ['formid' => $fid, 'available' => 1, 'identifier' => $v, 'orderby' => 1];
            if (empty($v) || !$this->formFields()->withWhere($where)->count()) {
                $valid = false;
                break;
            }
        }
        if ($valid === true) {
            return $order;
        }
        return 'main.id';
    }

    /**
     * 字段列表
     * @param string $where
     * @param string $fields
     * @param string $limit
     * @param string $order
     * @return array|bool|mixed|string|null
     * @throws \SlimCMS\Error\TextException
     */
    public function fieldList($where = '', $fields = '*', $limit = '', $order = 'displayorder desc,id')
    {
        $cachekey = $this->cacheKey(__FUNCTION__, func_get_args());
        $list = $this->redis->get($cachekey);
        if (empty($list)) {
            $list = $this->formFields()
                ->withWhere($where)
                ->withLimit($limit)
                ->withOrderby($order)
                ->fetchList($fields);
            $this->redis->set($cachekey, $list, 60);
        }
        return $list;
    }

    /**
     * 生成筛选条件
     * @param array $param
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    protected function searchCondition(array $param): OutputInterface
    {
        if (empty($param['fid'])) {
            return $this->output->withCode(21002);
        }
        if (!empty($param['searchFields'])) {
            $search_fields = $this->fieldList(['formid' => $param['fid'], 'available' => 1, 'identifier' => explode(',', $param['searchFields'])]);
        } else {
            $search_fields = $this->fieldList(['formid' => $param['fid'], 'available' => 1, 'search' => 1]);
        }

        $fields = $this->fieldList(['formid' => $param['fid'], 'available' => 1]);
        $data = $this->getFormValue($fields);

        $where = $tags = [];
        $currenturl = '';
        if (!empty($search_fields)) {
            foreach ($search_fields as $v) {
                //使模板标签支持条件筛选
                $arr = ['func', 'where', 'formid', 'order', 'fields', 'by', 'join', 'joinFields', 'cacheTime', 'url',
                    'page', 'pagesize', 'maxpages', 'autogoto', 'shownum'];
                if (aval($param, $v['identifier']) && !in_array($v['identifier'], $arr)) {
                    $data[$v['identifier']] = $param[$v['identifier']];
                }

                $val = aval($data, $v['identifier']);
                if (empty($v['rules']) && $val && preg_match('/,/', (string)$val)) {
                    list($s, $e) = explode(',', $val);
                    if (is_numeric($s) && is_numeric($e)) {
                        // [SQL安全改造] 惰性条件：参数由 withWhere->implode 统一收集
                        $where[] = [$v['identifier'] => ['between', $val]];
                    } elseif (is_numeric($s)) {
                        $where[] = [$v['identifier'] => ['>=', $s]];
                    } elseif (is_numeric($e)) {
                        $where[] = [$v['identifier'] => ['<=', $e]];
                    }
                } elseif ($v['datatype'] == 'checkbox') {
                    if (!empty($val)) {
                        foreach (explode(',', $val) as $val1) {
                            // [SQL安全改造] 惰性条件：参数由 withWhere->implode 统一收集
                            $where[] = [$v['identifier'] => ['find', $val1]];
                        }
                    }
                } elseif (in_array($v['datatype'], ['text', 'multitext', 'htmltext'])) {
                    if (!empty($val)) {
                        if (aval($v, 'precisesearch') == 1) {
                            $where[$v['identifier']] = $val;
                        } else {
                            // [SQL安全改造] 惰性条件：参数由 withWhere->implode 统一收集
                            $where[] = [$v['identifier'] => ['like', $val]];
                        }
                    }
                } elseif ($v['datatype'] == 'stepselect') {
                    if (!empty($val)) {
                        $where[$v['identifier']] = $this->_enumSubids($v['egroup'], $val);
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
                            if (aval($v, 'precisesearch') == 1) {
                                $where[$v['identifier']] = $val;
                            } else {
                                $where[] = $this->t()->field($v['identifier'], $val, 'like');
                            }
                        }
                    }
                }

                if (is_array($v['rules']) && !empty($v['rules'][$val])) {
                    $tags[] = [$v['identifier'], aval($v['rules'], $val)];
                } elseif (!empty($v['rules']) && !is_array($val) && preg_match('/,/', (string)$val)) {
                    $tags[] = [$v['identifier'], str_replace(',', '-', $val) . $v['units']];
                }

                if (isset($val)) {
                    if (strpos((string)$val, ',') !== false) {
                        if ($v['datatype'] == 'date') {
                            list($s, $e) = explode(',', $val);
                            $sdate = $s ? Time::gmdate($s) : '';
                            $edate = $e ? Time::gmdate($e) : '';
                            $currenturl .= '&' . $v['identifier'] . '_s' . '=' . $sdate .
                                '&' . $v['identifier'] . '_e' . '=' . $edate;
                            $data[$v['identifier'] . '_s'] = $sdate;
                            $data[$v['identifier'] . '_e'] = $edate;
                        } elseif ($v['datatype'] == 'datetime') {
                            list($s, $e) = explode(',', $val);
                            $sdate = $s ? Time::gmdate($s, 'dt') : '';
                            $edate = $e ? Time::gmdate($e, 'dt') : '';
                            $currenturl .= '&' . $v['identifier'] . '_s' . '=' . $sdate .
                                '&' . $v['identifier'] . '_e' . '=' . $edate;
                            $data[$v['identifier'] . '_s'] = $sdate;
                            $data[$v['identifier'] . '_e'] = $edate;
                        } else {
                            $val = str_replace(',', '`', $val);
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
        }
        $data = ['tags' => $tags, 'fields' => $fields, 'where' => $where, 'currentUrl' => $currenturl, 'get' => $data];
        return $this->output->withCode(200)->withData($data);
    }

    protected function _enumSubids($egroup, $evalue = 0)
    {
        $list = $this->t('sysenum')->withWhere(['egroup' => $egroup, 'reid' => $evalue])->onefieldList('evalue');
        foreach ($list as $v) {
            $list = array_merge($list, $this->_enumSubids($egroup, $v));
        }
        $list[] = $evalue;
        return array_unique($list);
    }

    /**
     * 联动数据某ID下的所有子ID
     * @param $egroup
     * @param int $evalue
     * @return OutputInterface
     */
    public function enumSubids($egroup, $evalue = 0)
    {
        if (empty($egroup)) {
            return $this->output->withCode(21002);
        }
        $list = $this->_enumSubids($egroup, $evalue);
        return $this->output->withCode(200)->withData(['ids' => $list]);
    }

    /**
     * 上传类
     * @return UploadInterface
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    private function upload(): UploadInterface
    {
        return $this->container->get(UploadInterface::class);
    }

    /**
     * 联动菜单数据
     * @param $egroup
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    private function enumsData($egroup): OutputInterface
    {
        $result = [];
        if ($egroup) {
            $list = $this->t('sysenum')->withWhere(['egroup' => $egroup])->withOrderby('displayorder')->fetchList('id,ename,evalue,reid');
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
     * 导出某个表单对应的数据
     * @param array $param
     * @return type
     */
    public function dataExport(array $param): OutputInterface
    {
        if (empty($param['fid'])) {
            return $this->output->withCode(21001);
        }
        $form = $this->formView((int)$param['fid'])->getData()['form'];
        if (empty($form)) {
            return $this->output->withCode(22006);
        }
        $dataListParam = $param;
        $dataListParam['by'] = 'desc';
        $dataListParam['pagesize'] = aval($param, 'pagesize', 1000);
        $dataListParam['fields'] = '*';
        $result = $this->dataList($dataListParam);
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
        if (is_callable([$this->t($form['table']), 'dataExportBefore'])) {
            $rs = $this->t($form['table'])->dataExportBefore($condition, $result);
            if (is_array($rs)) {
                if ($rs['code'] != 200) {
                    return $this->output->withCode($rs['code'], ['msg' => $rs['msg']]);
                }
            } else {
                if ($rs != 200) {
                    return $this->output->withCode($rs);
                }
            }
        }

        $fieldList = $this->fieldList($condition);//处理展示字段
        $style = 'height:30px;font-weight:bold;background-color:#f6f6f6;text-align:center;';
        $heads = [];
        $heads['id'] = ['title' => '序号', 'datatype' => 'int', 'style' => $style];
        if (aval($form, 'cpcheck') == 1) {
            $heads['ischeck'] = ['title' => '审核状态', 'datatype' => 'radio', 'style' => $style];
        }
        foreach ($fieldList as $v) {
            $v['style'] = $style;
            $heads[$v['identifier']] = $v;
        }
        $heads['createtime'] = ['title' => '创建时间', 'datatype' => 'date', 'style' => $style];
        $result = $result->withData(['heads' => $heads, 'form' => $form]);

        if (is_callable([$this->t($form['table']), 'dataExportAfter'])) {
            $rs = $this->t($form['table'])->dataExportAfter($result);
            if ($rs != 200) {
                return $this->output->withCode($rs);
            }
        }
        return $this->exportData($result);
    }

    /**
     * 数据导出
     * @param $param
     */
    public function exportData(OutputInterface $output): OutputInterface
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
            //清除同名旧文件
            is_file($filepath) && unlink($filepath);
            //删除1小时前生成的临时文件
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
                $title .= '<td style="' . aval($v, 'style') . '">' . $v['title'] . '</td>';
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
                        $format = aval($info, '_' . $k1 . '_format', '@');
                    } else {
                        $val = aval($info, $k1);
                        if (empty($val) && in_array($v1['datatype'], ['date', 'datetime'])) {
                            $val = '';
                        }
                        $format = aval($info, $k1 . '_format', '@');
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

    /**
     * 对数据库查询数据进行转换处理
     * @param array $fields
     * @param array $v
     * @return array
     * @throws \SlimCMS\Error\TextException
     */
    public function exchangeFieldValue(array $fields, array $v): array
    {
        if (empty($fields)) {
            return [];
        }
        isset($v['createtime']) && $v['_createtime'] = $v['createtime'] ? Time::gmdate($v['createtime'], 'dt') : '';
        foreach ($fields as $val) {
            $identifier = &$val['identifier'];
            if (!isset($v[$identifier])) {
                continue;
            }
            !empty($val['units']) && $v[$identifier . '_units'] = $val['units'];

            $rules = [];
            if (!empty($val['rules'])) {
                $rules = unserialize($val['rules']);

                //读取由表数据转成的规则
                if (!empty($rules) && count($rules) == 1) {
                    $rules = $this->tableDataRules($rules);
                }
            }
            switch ($val['datatype']) {
                case 'htmltext':
                    $v['_' . $identifier] = stripslashes($v[$identifier]);
                    break;
                case 'price':
                    if ($v[$identifier] && $v[$identifier] != '0.00') {
                        $v['_' . $identifier] = $v[$identifier] = (float)$v[$identifier];
                    } else {
                        $v['_' . $identifier] = '';
                    }
                    break;
                case 'date':
                    if ($v[$identifier]) {
                        $v[$identifier] = (int)$v[$identifier];
                        $v['_' . $identifier] = Time::gmdate($v[$identifier]);
                    } else {
                        $v['_' . $identifier] = $v[$identifier] = '';
                    }

                    break;
                case 'datetime':
                    if ($v[$identifier]) {
                        $v[$identifier] = (int)$v[$identifier];
                        $v['_' . $identifier] = Time::gmdate($v[$identifier], 'dt');
                    } else {
                        $v['_' . $identifier] = $v[$identifier] = '';
                    }
                    break;
                case 'month':
                    if ($v[$identifier]) {
                        $v[$identifier] = (int)$v[$identifier];
                        $v['_' . $identifier] = date('Y-m', $v[$identifier]);
                    } else {
                        $v['_' . $identifier] = $v[$identifier] = '';
                    }
                    break;
                case 'float':
                    if ($v[$identifier]) {
                        $v['_' . $identifier] = $v[$identifier] = (float)$v[$identifier];
                    } else {
                        $v['_' . $identifier] = $v[$identifier] = '';
                    }
                    break;
                case 'checkbox':
                    if (!empty($v[$identifier])) {
                        $arr = [];
                        $arrMore = [];
                        foreach (explode(',', (string)$v[$identifier]) as $_v) {
                            if (!empty($_v)) {
                                $name = aval($rules, $_v);
                                $arr[] = $name;
                                $arrMore[] = ['id' => $_v, 'name' => $name];
                            }
                        }
                        $v['_' . $identifier] = implode('、', $arr);
                        $v['__' . $identifier] = $arrMore;
                    }
                    break;
                case 'stepselect':
                    if (!empty($v[$identifier])) {
                        $row = $this->t('sysenum')
                            ->withWhere(['egroup' => $val['egroup'], 'evalue' => $v[$identifier]])
                            ->fetch();
                        $v['_' . $identifier] = !empty($row['alias']) ? $row['alias'] : aval($row, 'ename', '');
                    } else {
                        $v['_' . $identifier] = '';
                    }
                    break;
                case 'select':
                case 'radio':
                    $v['_' . $identifier] = aval($rules, $v[$identifier]);
                    break;
                case 'img':
                    $width = aval($this->config, 'imgWidth', 800);
                    $height = aval($this->config, 'imgHeight', 800);
                    $v['_' . $identifier] = copyImage($v[$identifier], $width, $height);
                    break;
                case 'imgs':
                    $width = aval($this->config, 'imgWidth', 800);
                    $height = aval($this->config, 'imgHeight', 800);
                    $img = !empty($v[$identifier]) ? unserialize($v[$identifier]) : [];
                    if (is_array($img)) {
                        foreach ($img as $k1 => $v1) {
                            $v1['originalImg'] = $v1['img'];
                            $v1['img'] = copyImage($v1['img'], $width, $height);
                            $img[$k1] = $v1;
                        }
                    }
                    $v['_' . $identifier] = $img;
                    break;
                case 'media':
                case 'addon':
                    $v['_' . $identifier] = $v[$identifier] ? trim($this->config['basehost'], '/') . $v[$identifier] : '';
                    break;
                case 'addons':
                case 'serialize':
                    $v['_' . $identifier] = unserialize($v[$identifier]);
                    break;
                case 'int':
                    if (!empty($val['rules'])) {
                        $result = $this->analysisRules($rules);
                        if ($result) {
                            $v['_' . $identifier] = $this->t($result['table'])
                                ->withWhere([$result['value'] => $v[$identifier]])
                                ->fetch($result['name']);
                        } else {
                            $v['_' . $identifier] = $v[$identifier] = (int)$v[$identifier];
                        }
                    } else {
                        $v['_' . $identifier] = $v[$identifier] = (int)$v[$identifier];
                    }
                    break;
                default:
                    $v['_' . $identifier] = Str::htmlspecialchars(html_entity_decode((string)$v[$identifier]), 'de');
                    break;
            }
        }
        return $v;
    }

    protected function request(): Request
    {
        return $this->i(Request::class);
    }

    /**
     * 获取表单提交数据
     * @param array $fields
     * @param array $olddata
     * @return array
     * @throws \SlimCMS\Error\TextException
     */
    protected function getFormValue(array $fields, array $olddata = []): array
    {
        $cfg = &$this->config;
        $data = [];
        foreach ($fields as $k => $v) {
            if ($v['infront'] != 1) {
                continue;
            }
            if (!empty($olddata['id']) && ($v['datatype'] == 'readonly' || $v['forbidedit'] == 2)) {
                continue;
            }
            $identifier = &$v['identifier'];
            if (!empty($v['rules'])) {
                $v['rules'] = unserialize($v['rules']);
            }
            //读取由表数据转成的规则
            if (!empty($v['rules']) && count($v['rules']) == 1) {
                $v['rules'] = $this->tableDataRules($v['rules']);
            }

            if (!empty($v['rules']) && in_array($v['datatype'], ['checkbox', 'select', 'radio'])) {
                $val = $this->request()->input($identifier);
                if (isset($val)) {
                    if (is_array($val)) {
                        $vals = $val;
                    } else {
                        $vals = $val || $val == '0' ? explode('`', $val) : [];
                    }
                    foreach ($vals as $val) {
                        if (array_key_exists($val, $v['rules'])) {
                            $data[$identifier][] = $val;
                        }
                    }
                    $data[$identifier] = !empty($data[$identifier]) ? implode(',', $data[$identifier]) : '';
                }
                if ($v['datatype'] == 'checkbox') {
                    $data[$identifier] = !empty($data[$identifier]) ? $data[$identifier] : '';
                }
            } else {
                switch ($v['datatype']) {
                    case 'htmltext':
                        $val = (string)$this->request()->input($identifier, 'htmltext');
                        if (isset($val)) {
                            $data[$identifier] = Str::filterHtml($val);
                        }
                        break;
                    case 'int':
                        $val = $this->request()->input($identifier);
                        if ($val && is_array($val)) {
                            $val = array_map('intval', $val);
                            $data[$identifier] = implode(',', $val);
                        } elseif ($val && strpos((string)$val, '`')) {
                            $arr = explode('`', $val);
                            $val = array_map('intval', $arr);
                            $data[$identifier] = implode(',', $val);
                        } else {
                            $val = $this->request()->input($identifier, 'int');
                            if (isset($val)) {
                                $data[$identifier] = $val;
                            }
                        }
                        break;
                    case 'stepselect':
                        $val = $this->request()->input($v['egroup'], 'int');
                        if (isset($val)) {
                            $data[$identifier] = $val;
                        }
                        break;
                    case 'float':
                    case 'tel':
                    case 'price':
                        $val = $this->request()->input($identifier, $v['datatype']);
                        if (isset($val)) {
                            $data[$identifier] = $val;
                        }
                        break;
                    case 'month':
                    case 'date':
                    case 'datetime':
                        $vals = $this->request()->input($identifier . '_s');
                        $vale = $this->request()->input($identifier . '_e');
                        if ($vals || $vale) {
                            $data[$identifier] = ($vals ? strtotime($vals) : '') . ',' . ($vale ? strtotime($vale) : '');
                        } else {
                            $val = $this->request()->input($identifier);
                            if (isset($val)) {
                                $data[$identifier] = strtotime($val);
                            }
                        }
                        break;
                    case 'imgs':
                        $imgurls = array();
                        if (!empty($olddata[$identifier])) {
                            $imgurls = unserialize($olddata[$identifier]);
                            foreach ($imgurls as $_k => $_v) {
                                $_v['text'] = str_replace("'", "`", $this->request()->input('imgmsg' . $_k));
                                $imgurls[$_k] = $_v;
                            }
                        }
                        $upload = $this->upload();
                        if ($cfg['clienttype'] > 0) {
                            for ($i = 0; $i < 10; $i++) {
                                $picUrl = $this->request()->input($identifier . '_' . $i, 'img');
                                if ($picUrl) {
                                    $info = $upload->metaInfo($picUrl, 'url,width')->getData();
                                    $key = md5($picUrl);
                                    $imgurls[$key]['img'] = $picUrl;
                                    $imgurls[$key]['text'] = '';
                                    $imgurls[$key]['width'] = $info['width'];
                                    $imgurls[$key]['height'] = $info['height'];
                                }
                            }
                        } else {
                            $res = $upload->getWebupload();
                            if ($res->getCode() == 200) {
                                $imgurls += (array)$res->getData();
                            }
                        }
                        $data[$identifier] = $imgurls ? serialize($imgurls) : '';
                        break;
                    case 'img':
                    case 'media':
                    case 'addon':
                    case 'superfile':
                        $val = $this->request()->input($identifier);
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
                            $data[$identifier] = $this->request()->input($identifier, $v['datatype']);
                            if (!empty($olddata[$identifier])) {
                                if (empty($data[$identifier])) {
                                    unset($data[$identifier]);
                                } else {
                                    $this->upload()->uploadDel($olddata[$identifier]);
                                }
                            }
                        }
                        break;
                    case 'addons':
                        $addons = [];
                        if (!empty($olddata[$identifier])) {
                            $addons = unserialize($olddata[$identifier]);
                        }
                        $uploads = $this->request->getUploadedFiles();
                        if (!empty($uploads[$identifier])) {
                            foreach ($uploads[$identifier] as $k1 => $v1) {
                                $res = $this->upload()->upload($v1, 'addon');
                                if ($res->getCode() == 200) {
                                    $addons[] = [
                                        'url' => $res->getData()['fileurl'] ?: '',
                                        'text' => $v1->getClientFilename(),
                                    ];
                                }
                            }
                        }
                        $data[$identifier] = $addons ? serialize($addons) : '';
                        break;
                    case 'serialize':
                        $val = $this->request()->input($identifier);
                        $data[$identifier] = is_array($val) ? serialize($val) : Str::htmlspecialchars($val, 'de');
                        break;
                    case 'password':
                        $val = $this->request()->input($identifier);
                        $val && $data[$identifier] = Crypt::pwd($val);
                        break;
                    default:
                        $val = $this->request()->input($identifier);
                        if (isset($val)) {
                            $data[$identifier] = $val;
                        }
                        break;
                }
            }
        }

        return $data;
    }

    /**
     * 删除附件
     * @param array $fields 字段列表
     * @param array $data 某条数据
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function delAttachment(array $fields, array $data): OutputInterface
    {
        if (empty($fields) || empty($data)) {
            return $this->output->withCode(21002);
        }
        foreach ($fields as $v) {
            if (empty($data[$v['identifier']])) {
                continue;
            }
            $upload = $this->upload();
            switch ($v['datatype']) {
                case 'htmltext':
                    //取出文章附件；
                    $pattern = '/(\\' . rtrim($this->setting['attachment']['dirname'], '/') . '.+?)(\"|\|| )/';
                    preg_match_all($pattern, stripslashes($data[$v['identifier']]) . ' ', $delname);
                    //移出重复附件；
                    $delname = array_unique($delname['1']);
                    foreach ($delname as $var) {
                        $upload->uploadDel($var);
                    }
                    break;
                case 'imgs':
                    foreach (unserialize($data[$v['identifier']]) as $p) {
                        $upload->uploadDel($p['img']);
                    }
                    break;
                default:
                    $upload->uploadDel($data[$v['identifier']]);
                    break;
            }
        }
        return $this->output->withCode(200);
    }

    /**
     * 获取表单提交所需要的信息
     * @param int $fid
     * @param array $fields
     * @param array $row
     * @param array $options
     * @return array
     * @throws \SlimCMS\Error\TextException
     */
    protected function formHtml($fid, array $fields, array $row = [], array $options = []): array
    {
        foreach ($fields as $k => $v) {
            $v['maxlength'] = $maxlength = !empty($v['maxlength']) ? 'maxlength="' . $v['maxlength'] . '"' : '';
            $v['rules'] = !empty($v['rules']) ? unserialize($v['rules']) : array();

            $datatype = $v['datatype'];
            if ($datatype == 'int' && !empty($v['rules']) && count($v['rules']) == 1) {
                $datatype = 'select';
            }

            //读取由表数据转成的规则
            if (!empty($v['rules']) && count($v['rules']) == 1) {
                $v['rules'] = $this->tableDataRules($v['rules']);
            }

            $v['default'] = !empty($row[$v['identifier']]) ? $row[$v['identifier']] : html_entity_decode((string)$v['default']);
            $v['csrfToken'] = $this->request->getAttribute('csrfToken');

            //Validform规则设置
            $v['checkrule'] = (empty($v['checkrule']) && $v['required'] == 1) ? '*' : $v['checkrule'];
            if ($v['required'] == 1) {
                $text = in_array($v['datatype'], ['select', 'radio', 'checkbox']) ? '请选择' : ($v['datatype'] == 'img' ? '请上传' : '请输入');
                empty($v['nullmsg']) && $v['nullmsg'] = $text . $v['title'];
                empty($v['tip']) && $v['tip'] = $text . $v['title'];
            }
            $datatypeStr = !empty($v['checkrule']) ? 'datatype="' . $v['checkrule'] . '" ' : '';
            if (empty($v['intro']) && $datatypeStr) {
                $v['intro'] = in_array($v['datatype'], array('select', 'radio', 'checkbox')) ? '必选' : '';
            }
            $nullmsg = !empty($v['nullmsg']) ? 'nullmsg="' . $v['nullmsg'] . '" ' : '';
            $ignore = empty($v['required']) ? 'ignore="ignore" ' : '';
            $tip = !empty($v['tip']) ? 'placeholder="' . $v['tip'] . '" ' : '';
            $errormsg = !empty($v['errormsg']) ? 'errormsg="' . $v['errormsg'] . '" ' : '';
            $readonly = !empty($row['id']) && $v['forbidedit'] == 2 ? ' readonly' : '';
            $v['validform'] = $validform = ' sucmsg="" ' . $datatypeStr . $nullmsg . $tip . $errormsg . $ignore . $readonly;

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
                    if ($this->config['clienttype'] > 0) {
                        //换行转换处理
                        $v['default'] = str_replace(array('&lt;br /&gt;', '&lt;br&gt;'), "\n", $v['default']);
                        $v['default'] = stripslashes($v['default']);
                        $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    } else {
                        $v['default'] = stripslashes($v['default']);
                        $config = ['identity' => aval($options, 'ueditorType', 'small')];
                        $v['field'] = $this->i(Ueditor::class)->ueditor($v['identifier'], $v['default'], $config);
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
                            $v['default'] = !empty($v['default']) ? $v['default'] :
                                (empty($row) ? date('Y-m', TIMESTAMP) : '');
                        }
                    } else {
                        $type = $v['datatype'] == 'date' ? 'd' : 'dt';
                        if (!empty($row[$v['identifier']])) {
                            $v['default'] = Time::gmdate($row[$v['identifier']], $type);
                        } else {
                            $v['default'] = !empty($v['default']) ?
                                Time::gmdate((TIMESTAMP + $v['default'] * 86400), $type) :
                                (empty($row) ? Time::gmdate(TIMESTAMP, $type) : '');
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
                    $v['imgs'] = !empty($v['default']) ? unserialize($v['default']) : [];
                    $v['fid'] = $fid;
                    $v['row'] = $row;

                    //清除session里的图片信息
                    $bigfile_info = $this->session()->get('bigfile_info');
                    if (!empty($bigfile_info) && is_array($bigfile_info)) {
                        foreach ($bigfile_info as $s_v) {
                            $this->upload()->uploadDel($s_v);
                        }
                    }
                    $this->session()->delete('bigfile_info');
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'img':
                    static $isLoadh5upload = 0;
                    $isLoadh5upload++;
                    $v['isLoadh5upload'] = $isLoadh5upload;
                    $v['fid'] = $fid;
                    $v['row'] = $row;
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'serialize':
                    $val = var_export(unserialize($v['default']), true);
                    $v['val'] = nl2br(str_replace(["array (\n", "),\n", ")"], '', $val));
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
                case 'int':
                    $v['default'] = (int)$v['default'];
                case 'float':
                    $v['default'] = (float)$v['default'];
                default:
                    $v['field'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                    break;
            }
            $fields[$k] = $v;
        }
        return $fields;
    }

    /**
     * 解析查询其它表单的规则
     * @param array $rules
     * @return array
     */
    protected function analysisRules(array $rules): array
    {
        if (empty($rules) || count($rules) != 1) {
            return [];
        }
        $table = array_key_first($rules);
        $tablepre = $this->setting['db']['tablepre'];
        $db = $this->t()->db();
        $tableName = $tablepre . str_replace($tablepre, '', $table);
        // [SQL安全改造] SHOW TABLES LIKE 参数化，防止规则中的表名注入
        if ($db->fetch('SHOW TABLES LIKE ?', [$tableName])) {
            $result = Str::htmlspecialchars($rules[$table], 'de');
            //筛选条件支持外部传参
            preg_match_all('|&#036;(\w+)&#036;|isU', $result, $mat);
            if (!empty($mat[1])) {
                foreach ($mat[1] as $v) {
                    $val = $this->request()->input($v);
                    $result = str_replace('&#036;' . $v . '&#036;', $val, $result);
                }
            }
            $result = json_decode($result, true);
            $order = $way = '';
            $orderby = aval($result, 'orderby');
            if ($orderby && strpos($orderby, ',')) {
                list($order, $way) = explode(',', aval($result, 'orderby'));
            } else {
                $order = $orderby;
            }
            $data = [];
            $data['table'] = $table;
            $data['value'] = $result['value'];
            $data['name'] = $result['name'];
            $data['condition'] = aval($result, 'condition');
            $data['limit'] = aval($result, 'limit');
            $data['order'] = (string)$order;
            $data['way'] = (string)$way;
            return $data;
        }
        return [];
    }

    /**
     * 转换查询其它表数据做为字段规则
     * @param $rules
     * @throws \SlimCMS\Error\TextException
     */
    public function tableDataRules(array $rules): array
    {
        if (empty($rules)) {
            return [];
        }
        $cacheTTL = aval($this->config, 'fieldRelateDataTTL');
        $cacheKey = $this->cacheKey(__FUNCTION__, $rules);
        $val = $cacheTTL ? $this->redis->get($cacheKey) : [];
        if (empty($val)) {
            $result = $this->analysisRules($rules);
            if ($result) {
                if (empty($result['name']) || empty($result['value'])) {
                    return $rules;
                }
                $field = str_replace('_', '', $result['value'] . ',' . $result['name']);
                $list = $this->t($result['table'])
                    ->withWhere($result['condition'])
                    ->withLimit($result['limit'])
                    ->withOrderby($result['order'], $result['way'])
                    ->fetchList($field);
                $fid = $this->t('forms')->withWhere(['table' => $result['table']])->fetch('id');
                $fields = $this->fieldList(['formid' => $fid, 'available' => 1]);

                $val = [];
                foreach ($list as $v) {
                    $fields && $v = $this->exchangeFieldValue($fields, $v);
                    //支持对应文字多种参数组合显示
                    if (strpos($result['name'], ',')) {
                        $arr = [];
                        foreach (explode(',', $result['name']) as $field) {
                            $arr[] = $v[$field];
                        }
                        $val[$v[$result['value']]] = implode('/', $arr);
                    } else {
                        $val[$v[$result['value']]] = $v[$result['name']];
                    }
                }
                $cacheTTL && $this->redis->set($cacheKey, $val, $cacheTTL);
            }
        }

        return $val;
    }

    /**
     * 后台列表展示字段
     * @param $fid
     * @param int $limit
     * @return OutputInterface
     */
    public function listFields(int $fid, $limit = 30, $fieldName = 'inlistcp'): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        $listFields = $this->fieldList(['formid' => $fid, 'available' => 1, $fieldName => 1], '*', $limit);
        return $this->output->withCode(200)->withData(['listFields' => $listFields]);
    }

    /**
     * 参与搜索字段
     * @param int $fid
     * @param string $fields
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function searchFields(int $fid, string $fields = ''): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        if (!empty($fields)) {
            $searchFields = $this->fieldList(['formid' => $fid, 'available' => 1, 'identifier' => explode(',', $fields)]);
        } else {
            $searchFields = $this->fieldList(['formid' => $fid, 'available' => 1, 'search' => 1]);
        }
        if (!empty($searchFields)) {
            foreach ($searchFields as &$v) {
                if (!empty($v['rules']) && count(unserialize($v['rules'])) == 1) {
                    $v['rules'] = serialize($this->tableDataRules(unserialize($v['rules'])));
                } elseif ($v['datatype'] == 'stepselect') {
                    $v['default'] = $this->request()->input($v['egroup'], 'int');
                    static $loadonce = 0;
                    $loadonce++;
                    $v['loadonce'] = $loadonce;
                    $template = 'block/fieldshtml/' . $v['datatype'];
                    $v['fieldHtml'] = $this->output->withData($v)->withTemplate($template)->analysisTemplate(true);
                }
            }
        }
        return $this->output->withCode(200)->withData(['searchFields' => $searchFields]);
    }

    /**
     * 参与排序字段
     * @param $fid
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function orderFields(int $fid): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        $where = ['formid' => $fid, 'available' => 1, 'orderby' => 1];
        $data = [];
        $data['orderFields'] = $this->formFields()->withWhere($where)->onefieldList('id');
        return $this->output->withCode(200)->withData($data);
    }

    /**
     * 所有可用的字段
     * @param int $fid
     * @return OutputInterface
     * @throws \SlimCMS\Error\TextException
     */
    public function allValidFields(int $fid): OutputInterface
    {
        if (empty($fid)) {
            return $this->output->withCode(27010);
        }
        $allValidFields = $this->fieldList(['formid' => $fid, 'available' => 1]);
        return $this->output->withCode(200)->withData(['allValidFields' => $allValidFields]);

    }

    /**
     * 数据有效性检测
     * @param int $fid 表单ID
     * @param array $data 修改/添加的数据
     * @param int $id 编辑的信息ID
     * @return OutputInterface
     * @throws TextException
     */
    public function validCheck(int $fid, array $data, int $id = 0): array
    {
        if (empty($fid)) {
            throw new TextException(27010);
        }
        $form = $this->formView($fid)->getData()['form'];
        if (empty($form)) {
            throw new TextException(22006);
        }
        $fields = $this->fieldList(['formid' => $fid, 'available' => 1]);
        foreach ($fields as $v) {
            $msg = $v['errormsg'] ?: $v['title'];
            //必填判断
            if ($v['required'] == 1 && empty($data[$v['identifier']])) {
                throw new TextException(21000, $v['title'] . '必填');
            }
            //唯一判断
            if ($v['unique'] == 1 && !empty($data[$v['identifier']])) {
                $exist_id = $this->t($form['table'])->withWhere([$v['identifier'] => $data[$v['identifier']]])->fetch('id');
                if ($exist_id && (empty($id) || $exist_id != $id)) {
                    throw new TextException(21000, $v['title'] . '已存在');
                }
            }
            if ($v['datatype'] == 'stepselect') {
                $v['rules'] = [];
                foreach ($this->enumsData($v['egroup'])->getData()['list'] as $v1) {
                    $v['rules'][$v1['evalue']] = $v1['ename'];
                }
            } elseif (!empty($v['rules'])) {
                $v['rules'] = unserialize($v['rules']);
                //读取由表数据转成的规则
                count($v['rules']) == 1 && $v['rules'] = $this->tableDataRules($v['rules']);
            }
            if (!empty($v['rules']) && in_array($v['datatype'], ['select', 'radio', 'stepselect'])) {
                if (!empty($data[$v['identifier']]) && !array_key_exists($data[$v['identifier']], $v['rules'])) {
                    throw new TextException(21000, $msg . '值不正确');
                }
            }
        }
        return $data;
    }
}
