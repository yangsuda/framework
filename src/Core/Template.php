<?php
/**
 * 模板解析加载
 * @author zhucy
 */

declare(strict_types=1);

namespace SlimCMS\Core;

use SlimCMS\Error\TextException;
use SlimCMS\Interfaces\TemplateInterface;


class Template implements TemplateInterface
{
    protected static $cacheFile = '';
    protected static $replacecode = ['search' => [], 'replace' => []];

    protected static function parseTemplate($tplfile, $cachefile)
    {
        if ($fp = @fopen(CSROOT . $tplfile, 'r')) {
            $template = @fread($fp, filesize(CSROOT . $tplfile));
            fclose($fp);
        } elseif ($fp = @fopen($filename = substr(CSROOT . $tplfile, 0, -4) . '.php', 'r')) {
            $template = static::getPHPTemplate(@fread($fp, filesize($filename)));
            fclose($fp);
        } else {
            throw new TextException(21052, ['title' => $tplfile]);
        }
        if (!@$fp = fopen(CSDATA . $cachefile, 'w')) {
            throw new TextException(21053, ['title' => $cachefile]);
        }
        $template = static::formatTemplate($template);

        flock($fp, 2);
        fwrite($fp, $template);
        fclose($fp);
    }

    protected static function formatTemplate($template)
    {
        $var_regexp = "((\\\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(\-\>)?[a-zA-Z0-9_\x7f-\xff]*)(\[[a-zA-Z0-9_\-\.\"\'\[\]\$\x7f-\xff]+\])*)";
        $const_regexp = "([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)";

        $template = preg_replace("/([\n\r]+)\t+/s", "\\1", $template);
        $template = preg_replace("/\<\!\-\-\{(.+?)\}\-\-\>/s", "{\\1}", $template);
        $template = preg_replace_callback("/[\n\r\t]*\{eval\s+(.+?)\s*\}[\n\r\t]*/is", [get_called_class(), 'evalTag'], $template);
        $template = preg_replace_callback("/[\n\r\t]*\{list\s+(.+?)\s*\}[\n\r\t]*/is", [get_called_class(), 'listTag'], $template);
        $template = preg_replace("/\{\/list\}/i", "<?php }} ?>", $template);
        $template = preg_replace_callback("/[\n\r\t]*\{data\s+(.+?)\s*\}[\n\r\t]*/is", [get_called_class(), 'dataTag'], $template);
        $template = preg_replace("/\{(\\\$[a-zA-Z0-9_\-\>\[\]\'\"\$\.\x7f-\xff]+)\}/s", "<?=\\1?>", $template);
        $template = preg_replace_callback("/$var_regexp/s", [get_called_class(), 'addquote'], $template);
        $template = preg_replace_callback("/\<\?\=\<\?\=$var_regexp\?\>\?\>/s", [get_called_class(), 'addquote'], $template);

        $template = preg_replace_callback("/[\n\r\t]*\{template\s+(.+?)\}[\n\r\t]*/is", [get_called_class(), 'loadTemplateTag'], $template);
        $template = preg_replace_callback("/[\n\r\t]*\{pluginHook\s+(.+?)\s*\}[\n\r\t]*/is", [get_called_class(), 'loadPluginHookTag'], $template);
        $template = preg_replace_callback("/[\n\r\t]*\{echo\s+(.+?)\}[\n\r\t]*/is", [get_called_class(), 'echoTag'], $template);

        $template = preg_replace_callback("/[\n\r\t]*\{url\s+(.+?)\}[\n\r\t]*/is", [get_called_class(), 'urlTag'], $template);

        $template = preg_replace_callback("/([\n\r\t]*)\{if\s+(.+?)\}([\n\r\t]*)/is", [get_called_class(), 'ifTag'], $template);
        $template = preg_replace_callback("/([\n\r\t]*)\{elseif\s+(.+?)\}([\n\r\t]*)/is", [get_called_class(), 'elseifTag'], $template);
        $template = preg_replace("/\{else\}/i", "<? } else { ?>", $template);
        $template = preg_replace("/\{\/if\}/i", "<? } ?>", $template);

        $template = preg_replace_callback("/[\n\r\t]*\{loop\s+(\S+)\s+(\S+)\}[\n\r\t]*/is", [get_called_class(), 'loopTag'], $template);
        $template = preg_replace_callback("/[\n\r\t]*\{loop\s+(\S+)\s+(\S+)\s+(\S+)\}[\n\r\t]*/is", [get_called_class(), 'loopTag'], $template);
        $template = preg_replace("/\{\/loop\}/i", "<? } ?>", $template);

        $template = preg_replace_callback("/[\n\r\t]*\{for\s+(\S+)\s+(\S+)\s+(\S+)\}[\n\r\t]*/is", [get_called_class(), 'forTag'], $template);
        $template = preg_replace("/\{\/for\}/i", "<? } ?>", $template);

        $template = preg_replace("/\{$const_regexp\}/s", "<?=\\1?>", $template);
        if (!empty(static::$replacecode)) {
            $template = str_replace(static::$replacecode['search'], static::$replacecode['replace'], $template);
        }
        $template = preg_replace("/ \?\>[\n\r]*\<\? /s", " ", $template);

        $template = preg_replace_callback("/\"(http)?[\w\.\/:]+\?[^\"]+?&[^\"]+?\"/", [get_called_class(), 'transamp'], $template);
        $template = preg_replace("/\<\?(\s{1})/is", "<?php\\1", $template);
        $template = preg_replace("/\<\?\=(.+?)\?\>/is", "<?php echo \\1??'';?>", $template);
        return $template;
    }

    protected static function evalTag($matches)
    {
        $php = $matches[1];
        $php = str_replace('\"', '"', $php);
        $i = count(static::$replacecode['search']);
        static::$replacecode['search'][$i] = $search = "<!--EVAL_TAG_$i-->";
        static::$replacecode['replace'][$i] = "<?php $php?>";
        return $search;
    }

    protected static function getPHPTemplate($content)
    {
        $pos = strpos($content, "\n");
        return $pos !== false ? substr($content, $pos + 1) : $content;
    }

    protected static function transamp($matches)
    {
        $str = $matches[0];
        $str = str_replace('&amp;amp;', '&amp;', $str);
        $str = str_replace('\"', '"', $str);
        return $str;
    }

    protected static function addquote($matches)
    {
        $var = '<?=' . $matches[1] . '?>';
        $var = preg_replace("/\[([a-zA-Z0-9_\-\.\x7f-\xff]+)\]/s", "['\\1']", $var);
        return str_replace("\\\"", "\"", $var);
    }

    protected static function loadTemplateTag($matches)
    {
        $param = str_replace(['<?=', '?>'], ["'.", ".'"], $matches[1]);
        $expr = '<?php include SlimCMS\Core\Template::loadTemplate(\'' . $param . '\'); ?>';
        return static::stripvtags($expr);
    }

    protected static function loadPluginHookTag($matches)
    {
        $file = str_replace(['<?=', '?>'], ["'.", ".'"], $matches[1]);
        $tpldir = '/template/' . CURSCRIPT . '/plugin/';
        $templates = [];
        // 创建一个递归目录迭代器
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(CSROOT . 'template/admincp/plugin/'));
        // 遍历目录和子目录
        foreach ($iterator as $f) {
            $pathName = realpath($f->getPathname());
            if ($f->getExtension() === 'htm' && strpos($pathName, DIRECTORY_SEPARATOR . 'hook' . DIRECTORY_SEPARATOR . $file . '.htm') !== false) {
                $templates[] = str_replace(realpath(CSROOT . '/template'), '', $pathName);
            }
        }
        // 创建一个递归目录迭代器
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(CSROOT . 'template/main/plugin/'));
        // 遍历目录和子目录
        foreach ($iterator as $f) {
            $pathName = realpath($f->getPathname());
            if ($f->getExtension() === 'htm' && strpos($pathName, DIRECTORY_SEPARATOR . 'hook' . DIRECTORY_SEPARATOR . $file . '.htm') !== false) {
                $templates[] = str_replace(realpath(CSROOT . '/template'), '', $pathName);
            }
        }
        $files = [];
        foreach ($templates as $t) {
            $files[] = static::stripvtags('<?php include SlimCMS\Core\Template::loadTemplate(\'' . trim($t, '.htm') . '\',true); ?>');
        }
        return implode("\n", $files);
    }

    protected static function dataTag($matches)
    {
        $tagcode = $matches[1];
        $row = [];
        $tags = explode(' ', $tagcode);
        foreach ($tags as $v) {
            if ($v) {
                $v = preg_replace('/["\']/', '', $v);
                list($key, $val) = explode('=', $v);
                if (strpos($val, '$') !== false) {
                    $val = preg_replace("/\[([\w\-\.]+)\]/s", "['\\1']", trim($val));
                    $val = str_replace("\\\"", "\"", $val);
                    $row[trim($key)] = '\'.(isset(' . $val . ')?' . $val . ':\'\').\'';
                } else {
                    $row[trim($key)] = trim($val);
                }
            }
        }
        $data = json_encode($row);
        $func = aval($row, 'func', 'dataCount');
        $key = aval($row, 'key', 'count');
        $i = count(static::$replacecode['search']);
        static::$replacecode['search'][$i] = $search = "<!--" . __FUNCTION__ . "_$i-->";
        static::$replacecode['replace'][$i] = "<?php \$_tagData = \App\Model\main\TagsModel::$func('$data'); echo aval(\$_tagData,'$key');?>";
        return $search;
    }

    protected static function echoTag($matches)
    {
        $expr = '<?php echo ' . $matches[1] . '??\'\'; ?>';
        return static::stripvtags($expr);
    }

    protected static function urlTag($matches)
    {
        $config = getConfig();
        $param = str_replace(['<?=', '?>'], ['".', '."'], $matches[1]);
        if (!empty($config['cfg']['urlEncrypt']) && strpos($param, '\'+')) {
            $expr = '<?php echo "' . $param . '"; ?>';
        } else {
            $expr = '<?php echo \SlimCMS\Core\Forms::url("' . $param . '"); ?>';
        }
        return static::stripvtags($expr);
    }

    protected static function ifTag($matches)
    {
        $expr = $matches[1] . '<?php if(' . $matches[2] . ') { ?>' . $matches[3];
        return static::stripvtags($expr);
    }

    protected static function elseifTag($matches)
    {
        $expr = $matches[1] . '<?php } elseif(' . $matches[2] . ') { ?>' . $matches[3];
        return static::stripvtags($expr);
    }

    protected static function loopTag($matches)
    {
        if (!empty($matches[3])) {
            $expr = '<?php if(!empty(' . $matches[1] . ') && is_array(' . $matches[1] . ')) foreach(' . $matches[1] . ' as ' . $matches[2] . ' => ' . $matches[3] . ') { ?>';
        } else {
            $expr = '<?php if(!empty(' . $matches[1] . ') && is_array(' . $matches[1] . ')) foreach(' . $matches[1] . ' as ' . $matches[2] . ') { ?>';
        }
        return static::stripvtags($expr);
    }

    protected static function forTag($matches)
    {
        $expr = '<?php for($' . $matches[1] . '=' . $matches[2] . ';$' . $matches[1] . '<' . $matches[3] . ';$' . $matches[1] . '++) { ?>';
        return static::stripvtags($expr);
    }

    /**
     * $$v[xxx]类似的标签解析成${$v[xxx]}(php7下解释成$$v下的[xxx]) zhucy 2017.2.20
     * @param unknown_type $code
     */
    protected static function parsePHP($code)
    {
        if (is_array($code)) {
            return array_map([get_called_class(), 'parsePHP'], $code);
        }
        if (preg_match('/\$\$([_\w\d\[\]\'\']+)/', $code)) {
            $code = preg_replace('/\$\$([_\w\d\[\]\'\']+)/', '${$\\1}', $code);
        }
        return $code;
    }

    protected static function stripvtags($expr, $statement = '')
    {
        $expr = preg_replace("/\<\?\=(\\\$.+?)\?\>/s", "\\1", $expr);
        $expr = str_replace("\\\"", "\"", $expr);
        $expr = static::parsePHP($expr);
        $statement = str_replace("\\\"", "\"", $statement);
        return $expr . $statement;
    }

    protected static function listTag($matches)
    {
        $tagcode = $matches[1];
        $row = [];
        $tags = explode(' ', $tagcode);
        foreach ($tags as $v) {
            if ($v) {
                $v = preg_replace('/["\']/', '', $v);
                list($key, $val) = explode('=', $v);
                if (strpos($val, '$') !== false) {
                    $val = preg_replace("/\[([\w\-\.]+)\]/s", "['\\1']", trim($val));
                    $val = str_replace("\\\"", "\"", $val);
                    $row[trim($key)] = '\'.(isset(' . $val . ')?' . $val . ':\'\').\'';
                } else {
                    $row[trim($key)] = trim($val);
                }
            }
        }
        $indexk = aval($row, 'index-key', 'k');
        $indexv = aval($row, 'index-value', 'v');

        $data = json_encode($row);
        $func = aval($row, 'func', 'dataList');
        $listkey = aval($row, 'listKey', 'list');
        $i = count(static::$replacecode['search']);
        static::$replacecode['search'][$i] = $search = "<!--" . __FUNCTION__ . "_$i-->";
        static::$replacecode['replace'][$i] = "<?php \$_tagList = \App\Model\main\TagsModel::$func('$data'); " .
            "if(!empty(\$_tagList['$listkey'])){foreach(\$_tagList['$listkey'] as \${$indexk}=>\${$indexv}){?>";
        return $search;
    }

    /**
     * {@inheritDoc}
     */
    public static function loadTemplate($file, $force = false)
    {
        if (strpos($file, CSROOT) === 0) {
            $force = true;
            $file = str_replace(CSROOT, '', $file);
            $file = preg_replace('/^\/template\//', '', $file);
        }
        if ($force === false) {
            $file = trim($file, '/');
            $tpldir = '/template/' . CURSCRIPT . '/';

            $tplfile = $tpldir . $file . '.htm';
            if (!is_file(CSROOT . $tplfile)) {
                $tplfile = dirname($tplfile) . '/default/' . substr($tplfile, (strlen(dirname($tplfile)) + 1));
                if (!is_file(CSROOT . $tplfile)) {
                    $tplfile = $tpldir . 'default/' . substr($tplfile, (strlen(dirname($tplfile)) + 1));
                }
            }
            if (!is_file(CSROOT . $tplfile)) {
                $tplfile = '/template/default/' . basename($file) . '.htm';
            }
            static::$cacheFile = 'template/' . CURSCRIPT . '_' . str_replace('/', '_', $file) . '.tpl.php';
        } else {
            $tplfile = '/template/' . $file . '.htm';
            if (!is_file(CSROOT . $tplfile)) {
                $tplfile = '/template/default/' . basename($file) . '.htm';
            }
            static::$cacheFile = 'template/' . md5($file) . '.tpl.php';
        }

        if (!is_file(CSROOT . $tplfile)) {
            throw new TextException(21052, ['title' => $tplfile]);
        }
        if (static::$cacheFile) {
            static::checktplrefresh($tplfile, static::$cacheFile);
            return CSDATA . static::$cacheFile;
        }
    }

    protected static function checkTplRefresh($maintpl, $cachefile)
    {
        $ftime = is_file(CSDATA . $cachefile) ? filemtime(CSDATA . $cachefile) : '';
        if (empty($ftime) || @filemtime(CSROOT . $maintpl) > $ftime) {
            static::parseTemplate($maintpl, $cachefile);
            return TRUE;
        }
        return FALSE;
    }
}