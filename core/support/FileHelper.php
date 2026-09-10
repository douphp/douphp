<?php

/**
 * DouPHP®
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-08
 */

namespace Dou\Core\Support;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 文件与目录通用工具（静态、无状态）。
 *
 * 兼容 PHP 5.6+。
 */
class FileHelper
{
    /**
     * 递归删除目录。
     *
     * @param mixed $dir
     * @param bool $only_file
     * @param string $refer_dir
     * @param bool $include_subdir
     * @return int|void
     */
    public static function delDir($dir, $only_file = false, $refer_dir = '', $include_subdir = true)
    {
        if (!$dir || !@is_dir($dir)) {
            return 0;
        }

        if ($dir[strlen($dir) - 1] != DIRECTORY_SEPARATOR) {
            $dir .= DIRECTORY_SEPARATOR;
        }
        if ($refer_dir) {
            if (!@is_dir($refer_dir)) {
                return 0;
            }
            if ($refer_dir[strlen($refer_dir) - 1] != DIRECTORY_SEPARATOR) {
                $refer_dir .= DIRECTORY_SEPARATOR;
            }
        }

        $opendir = $refer_dir ? $refer_dir : $dir;
        if ($handle = @opendir($opendir)) {
            while (($file = @readdir($handle)) !== false) {
                if ($file != '.' && $file != '..') {
                    if (@is_dir($opendir . $file) && !is_link($opendir . $file)) {
                        if ($include_subdir) {
                            self::delDir($dir . $file, $only_file, ($refer_dir ? $refer_dir . $file : ''), $include_subdir);
                        }
                    } else {
                        @unlink($dir . $file);
                    }
                }
            }
            closedir($handle);

            if (!$only_file) {
                @rmdir($dir);
            }
        }
    }

    /**
     * 递归复制目录。
     *
     * @param mixed $source_dir
     * @param mixed $destination_dir
     * @param bool $del_source
     * @param bool $skip
     * @param bool $destination_exists
     * @param string $skip_dir
     * @return int|void
     */
    public static function copyDir($source_dir, $destination_dir, $del_source = false, $skip = false, $destination_exists = false, $skip_dir = '')
    {
        if (!$source_dir || !@is_dir($source_dir)) {
            return 0;
        }
        if (!is_dir($destination_dir)) {
            mkdir($destination_dir);
        }

        if ($source_dir[strlen($source_dir) - 1] != DIRECTORY_SEPARATOR) {
            $source_dir .= DIRECTORY_SEPARATOR;
        }
        if ($destination_dir) {
            if (!@is_dir($destination_dir)) {
                return 0;
            }
            if ($destination_dir[strlen($destination_dir) - 1] != DIRECTORY_SEPARATOR) {
                $destination_dir .= DIRECTORY_SEPARATOR;
            }
        }

        if ($handle = @opendir($source_dir)) {
            while (($file = @readdir($handle)) !== false) {
                if ($file != '.' && $file != '..') {
                    if (@is_dir($source_dir . $file) && !is_link($source_dir . $file)) {
                        self::copyDir($source_dir . $file, $destination_dir . $file, $del_source, $skip, $destination_exists, $skip_dir);
                    } else {
                        $should_skip = $skip;
                        if ($skip && $skip_dir) {
                            $should_skip = strpos($destination_dir . $file, $skip_dir) !== false;
                        }

                        if ($should_skip) {
                            if (!file_exists($destination_dir . $file)) {
                                copy($source_dir . $file, $destination_dir . $file);
                            }
                        } elseif ($destination_exists) {
                            if (file_exists($destination_dir . $file)) {
                                copy($source_dir . $file, $destination_dir . $file);
                            }
                        } else {
                            copy($source_dir . $file, $destination_dir . $file);
                        }

                        if ($del_source) {
                            @unlink($source_dir . $file);
                        }
                    }
                }
            }
            closedir($handle);

            if ($del_source) {
                @rmdir($source_dir);
            }
        }
    }

    /**
     * 递归列出目录与文件路径（带前缀裁剪）。
     *
     * @param mixed $dir
     * @param mixed $del_path
     * @param array $dir_list
     * @return mixed
     */
    public static function readDirFile($dir, $del_path = ROOT_PATH, &$dir_list = array())
    {
        if ($dir[strlen($dir) - 1] != '/') {
            $dir .= '/';
        }
        if (@is_dir($dir)) {
            if ($dh = @opendir($dir)) {
                while (($file = @readdir($dh)) !== false) {
                    if ((is_dir($dir . $file)) && $file != '.' && $file != '..') {
                        $dir_list[] = '#' . str_replace($del_path, '', $dir . $file);
                        self::readDirFile($dir . $file, $del_path, $dir_list);
                    } else {
                        if ($file != '.' && $file != '..') {
                            $dir_list[] = '#' . str_replace($del_path, '', $dir . $file);
                        }
                    }
                }

                closedir($dh);
            }
        }

        return $dir_list;
    }

    /**
     * 按路径深度降序，对调用方给出的目录尝试 rmdir。
     *
     * 仅删除空目录；非空目录 rmdir 会失败并被静默跳过，避免误删其它模块仍在用的父级。
     * 调用方需自行去重并保证传入的是绝对路径，本方法不递归扫描磁盘。
     *
     * @param array $dirs 绝对路径数组
     * @return void
     */
    public static function removeEmptyDirs(array $dirs)
    {
        if (empty($dirs)) {
            return;
        }

        $unique = array_values(array_unique($dirs));

        usort($unique, function ($a, $b) {
            $da = substr_count(str_replace('\\', '/', $a), '/');
            $db = substr_count(str_replace('\\', '/', $b), '/');
            if ($da !== $db) {
                return $db - $da;
            }
            return strcmp($b, $a);
        });

        foreach ($unique as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }

    /**
     * 列出目录下一级子目录名。
     *
     * @param mixed $dir
     * @return array
     */
    public static function getSubdirs($dir)
    {
        $options = array();

        if (!is_dir($dir) || !is_readable($dir)) {
            return $options;
        }

        $files = scandir($dir);

        if ($files === false) {
            return $options;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fullPath = $dir . DIRECTORY_SEPARATOR . $file;

            if (is_dir($fullPath)) {
                $options[] = $file;
            }
        }

        return $options;
    }

    /**
     * 按文件时间倒序整理文件列表信息。
     *
     * @param mixed $files
     * @return mixed
     */
    public static function buildFileListByTime($files)
    {
        if (is_array($files) && count($files)) {
            $info = $infos = array();
            foreach ($files as $id => $file) {
                $ext = self::extension($file);

                $filename = $info['filename'] = basename($file);
                if (filesize($file) < 1048576) {
                    $info['filesize'] = round(filesize($file) / 1024, 2) . "K";
                } else {
                    $info['filesize'] = round(filesize($file) / (1024 * 1024), 2) . "M";
                }
                $info['maketime'] = date('Y-m-d H:i:s', filemtime($file));
                $info['ext'] = $ext;
                $info['showname'] = $filename;

                if (preg_match('/_([0-9])+\\' . '.' . $ext . '$/', $filename, $match)) {
                    $info['number'] = $match[1];
                } else {
                    $info['number'] = '';
                }
                $infos[] = $info;
            }

            $flag = array();
            foreach ($infos as $v) {
                $flag[] = $v['maketime'];
            }
            array_multisort($flag, SORT_DESC, $infos);

            return $infos;
        }
    }

    /**
     * @param mixed $file
     * @return string
     */
    public static function extension($file)
    {
        $file = is_string($file) ? $file : '';
        if (empty($file)) {
            return '';
        }

        return pathinfo($file, PATHINFO_EXTENSION);
    }

    /**
     * @param mixed $file
     * @return string
     */
    public static function filename($file)
    {
        $file = is_string($file) ? $file : '';
        if (empty($file)) {
            return '';
        }

        return pathinfo($file, PATHINFO_FILENAME);
    }

    /**
     * @param mixed $file
     * @return string
     */
    public static function permission($file)
    {
        if (file_exists($file)) {
            if (is_dir($file)) {
                $dir = $file;
                $fp = @fopen($dir . '/test.txt', 'w');
                if ($fp) {
                    @fclose($fp);
                    @unlink($dir . '/test.txt');
                    $status = 'write';
                } else {
                    $status = 'no_write';
                }
            } else {
                $fp = @fopen($file, 'a+');
                if ($fp) {
                    @fclose($fp);
                    $status = 'write';
                } else {
                    $status = 'no_write';
                }
            }
        } else {
            $status = 'no_exist';
        }

        return $status;
    }
}
