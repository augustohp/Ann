#!/usr/bin/env php
<?php
/*
 * Pirum
 *
 *  Copyright (c) 2009-2011 Fabien Potencier
 *
 *	Permission is hereby granted, free of charge, to any person obtaining a copy
 *	of this software and associated documentation files (the "Software"), to deal
 *	in the Software without restriction, including without limitation the rights
 *	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *	copies of the Software, and to permit persons to whom the Software is furnished
 *	to do so, subject to the following conditions:
 *
 *	The above copyright notice and this permission notice shall be included in all
 *	copies or substantial portions of the Software.
 *
 *	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *	THE SOFTWARE.
 */

// only run Pirum automatically when this file is called directly
// from the command line
if (isset($argv[0]) && __FILE__ == realpath($argv[0])) {
    $cli = new Pirum_CLI($argv);
    exit($cli->run());
}

/**
 * Command line interface for Pirum.
 *
 * @package    Pirum
 * @author     Fabien Potencier <fabien@symfony.com>
 */
class Pirum_CLI
{
    const VERSION = '@package_version@';

    protected $options;
    protected $formatter;
    protected $commands = array(
        'build',
        'add',
        'remove',
    );

    public function __construct(array $options)
    {
        $this->options = $options;
        $this->formatter = new Pirum_CLI_Formatter();
    }

    public function run()
    {
        echo $this->getUsage();

        if (!isset($this->options[1])) {
            return 0;
        }

        $command = $this->options[1];
        if (!$this->isCommand($command)) {
            echo $this->formatter->formatSection('ERROR', sprintf('"%s" is not a valid command.', $command));

            return 1;
        }

        echo $this->formatter->format(sprintf("Running the %s command:\n", $command), 'COMMENT');

        if (!isset($this->options[2]) || !is_dir($this->options[2])) {
            echo $this->formatter->formatSection('ERROR', "You must give the root directory of the PEAR channel server.");

            return 1;
        }

        $target = $this->options[2];

        $ret = 0;
        try {
            switch ($command) {
                case 'build':
                    $this->runBuild($target);
                    break;
                case 'add':
                    $ret = $this->runAdd($target);
                    break;
                case 'remove':
                    $ret = $this->runRemove($target);
                    break;
            }

            if (0 == $ret) {
                echo $this->formatter->formatSection('INFO', sprintf("Command %s run successfully.", $command));
            }
        } catch (Exception $e) {
            echo $this->formatter->formatSection('ERROR', sprintf("%s (%s, %s)", $e->getMessage(), get_class($e), $e->getCode()));

            return 1;
        }

        return $ret;
    }

    public static function removeDir($target)
    {
        $fp = opendir($target);
        while (false !== $file = readdir($fp)) {
            if (in_array($file, array('.', '..'))) {
                continue;
            }

            if (is_dir($target.'/'.$file)) {
                self::removeDir($target.'/'.$file);
            } else {
                unlink($target.'/'.$file);
            }
        }
        closedir($fp);
        rmdir($target);
    }

    public static function version()
    {
        if (strpos(self::VERSION, '@package_version') === 0) {
            return 'DEV';
        } else {
            return self::VERSION;
        }
    }

    protected function runRemove($target)
    {
        if (!isset($this->options[3])) {
            echo $this->formatter->formatSection('ERROR', "You must pass a PEAR package name.");

            return 1;
        }

        if (!preg_match(Pirum_Package::PACKAGE_FILE_PATTERN, $this->options[3])) {
            echo $this->formatter->formatSection('ERROR', sprintf('The PEAR package "%s" filename is badly formatted.', $this->options[3]));

            return 1;
        }

        if (!is_file($target.'/get/'.basename($this->options[3]))) {
            echo $this->formatter->formatSection('ERROR', sprintf('The PEAR package "%s" does not exist in this channel.', $this->options[3]));

            return 1;
        }

        unlink($target.'/get/'.basename($this->options[3]));
        unlink($target.'/get/'.substr_replace(basename($this->options[3]), '.tar', -4));

        $this->runBuild($target);
    }

    protected function runAdd($target)
    {
        if (!isset($this->options[3])) {
            echo $this->formatter->formatSection('ERROR', "You must pass a PEAR package file path.");

            return 1;
        }

        if (!is_file($this->options[3])) {
            echo $this->formatter->formatSection('ERROR', sprintf('The PEAR package "%s" does not exist.', $this->options[3]));

            return 1;
        }

        if (!preg_match(Pirum_Package::PACKAGE_FILE_PATTERN, $this->options[3])) {
            echo $this->formatter->formatSection('ERROR', sprintf('The PEAR package "%s" filename is badly formatted.', $this->options[3]));

            return 1;
        }

        if (!is_dir($target.'/get')) {
            mkdir($target.'/get', 0777, true);
        }

        copy($this->options[3], $target.'/get/'.basename($this->options[3]));

        $this->runBuild($target);

        $package = $this->options[3];
    }

    protected function runBuild($target)
    {
        $builder = new Pirum_Builder($target, $this->formatter);
        $builder->build();
    }

    protected function isCommand($cmd) {
        return in_array($cmd, $this->commands);
    }

    protected function getUsage()
    {
        return $this->formatter->format(sprintf("Pirum %s by Fabien Potencier\n", self::version()), 'INFO') .
               $this->formatter->format("Available commands:\n", 'COMMENT') .
               "  pirum build target_dir\n" .
               "  pirum add target_dir Pirum-1.0.0.tgz\n" .
               "  pirum remove target_dir Pirum-1.0.0.tgz\n\n";
    }
}

/**
 * Builds all the files for a PEAR channel.
 *
 * @package    Pirum
 * @author     Fabien Potencier <fabien@symfony.com>
 */
class Pirum_Builder
{
    protected $buildDir;
    protected $targetDir;
    protected $server;
    protected $packages;
    protected $formatter;

    public function __construct($targetDir, $formatter = false)
    {
        if (!file_exists($targetDir.'/pirum.xml')) {
            throw new InvalidArgumentException('You must create a pirum.xml configuration file in the root of the target directory.');
        }

        if (!is_dir($targetDir.'/get')) {
            mkdir($targetDir.'/get', 0777, true);
        }

        $this->server = simplexml_load_file($targetDir.'/pirum.xml');

        if (!$this->server) {
            throw new InvalidArgumentException('Your pirum.xml configuration is invalid - <server> tag missing.');
        }

        $emptyFields = array();
        if (empty($this->server->name)) {
            $emptyFields[] = 'name';
        }
        if (empty($this->server->summary)) {
            $emptyFields[] = 'summary';
        }
        if (empty($this->server->url)) {
            $emptyFields[] = 'url';
        }

        if (!empty($emptyFields)) {
            throw new InvalidArgumentException(sprintf('You must fill required tags in your pirum.xml configuration file: %s.', implode(', ', $emptyFields)));
        }

        $this->server->url = rtrim($this->server->url, '/');

        $this->formatter = $formatter;
        $this->targetDir = $targetDir;
        $this->buildDir  = sys_get_temp_dir().'/pirum_build_'.uniqid();
        mkdir($this->buildDir.'/rest', 0777, true);
    }

    public function __destruct()
    {
        Pirum_CLI::removeDir($this->buildDir);
    }

    public function build()
    {
        $this->extractInformationFromPackages();

        $this->fixArchives();
        $this->buildChannel();
        $this->buildMaintainers();
        $this->buildCategories();
        $this->buildPackages();
        $this->buildReleasePackages();
        $this->buildIndex();
        $this->buildCss();
        $this->buildFeed();

        $this->updateTargetDir();
    }

    protected function fixArchives()
    {
        // create tar files when missing
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->targetDir.'/get'), RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!preg_match(Pirum_Package::PACKAGE_FILE_PATTERN, $file->getFileName(), $match)) {
                continue;
            }

            $tar = preg_replace('/\.tgz/', '.tar', $file);
            if (!file_exists($tar)) {
                if (function_exists('gzopen')) {
                    $gz = gzopen($file, 'r');
                    $fp = fopen(str_replace('.tgz', '.tar', $file), 'wb');
                    while (!gzeof($gz)) {
                        fwrite($fp, gzread($gz, 10000));
                    }
                    gzclose($gz);
                    fclose($fp);
                } else {
                    system('cd '.$target.'/get/ && gunzip -c -f '.basename($file));
                }
            }
        }
    }

    protected function updateTargetDir()
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Updating PEAR server files.");

        $this->updateChannel();
        $this->updateIndex();
        $this->updateCss();
        $this->updateFeed();
        $this->updatePackages();
    }

    protected function updateChannel()
    {
        if (!file_exists($this->targetDir.'/channel.xml') || file_get_contents($this->targetDir.'/channel.xml') != file_get_contents($this->buildDir.'/channel.xml')) {
            if (file_exists($this->targetDir.'/channel.xml')) {
                unlink($this->targetDir.'/channel.xml');
            }

            rename($this->buildDir.'/channel.xml', $this->targetDir.'/channel.xml');
        }
    }

    protected function updateIndex()
    {
        copy($this->buildDir.'/index.html', $this->targetDir.'/index.html');
    }

    protected function updateCss()
    {
        copy($this->buildDir.'/pirum.css', $this->targetDir.'/pirum.css');
    }

    protected function updateFeed()
    {
        copy($this->buildDir.'/feed.xml', $this->targetDir.'/feed.xml');
    }

    protected function updatePackages()
    {
        $this->mirrorDir($this->buildDir.'/rest', $this->targetDir.'/rest');
    }

    protected function buildFeed()
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Building feed.");

        $entries = '';
        foreach ($this->packages as $package) {
            foreach ($package['releases'] as $release) {
                $date = date(DATE_ATOM, strtotime($release['date']));

                reset($release['maintainers']);
                $maintainer = current($release['maintainers']);

                $entries .= <<<EOF
    <entry>
        <title>{$package['name']} {$release['version']} ({$release['stability']})</title>
        <link href="{$this->server->url}/get/{$package['name']}-{$release['version']}.tgz" />
        <id>{$this->server->url}/get/{$package['name']}-{$release['version']}.tgz</id>
        <author>
            <name>{$maintainer['nickname']}</name>
        </author>
        <updated>$date</updated>
        <content>
            {$release['notes']}
        </content>
    </entry>
EOF;
            }
        }

        $date = date(DATE_ATOM);
        $index = <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <id>{$this->server->url}</id>
    <updated>$date</updated>
    <title>{$this->server->summary} Latest Releases</title>
    <link href="{$this->server->url}/feed.xml" rel="self" />
    <author>
        <name>{$this->server->url}</name>
    </author>

$entries
</feed>
EOF;

        file_put_contents($this->buildDir.'/feed.xml', $index);
    }

    protected function buildCss()
    {
        if (file_exists($file = dirname(__FILE__).'/templates/pirum.css') ||
            file_exists($file = $this->buildDir.'/templates/pirum.css')) {
            $content = file_get_contents($file);
        } else {
            $content = <<<EOF
/*
Copyright (c) 2009, Yahoo! Inc. All rights reserved.
Code licensed under the BSD License:
http://developer.yahoo.net/yui/license.txt
version: 2.8.0r4
*/
html{color:#000;background:#FFF;}body,div,dl,dt,dd,ul,ol,li,h1,h2,h3,h4,h5,h6,pre,code,form,fieldset,legend,input,button,textarea,p,blockquote,th,td{margin:0;padding:0;}table{border-collapse:collapse;border-spacing:0;}fieldset,img{border:0;}address,caption,cite,code,dfn,em,strong,th,var,optgroup{font-style:inherit;font-weight:inherit;}del,ins{text-decoration:none;}li{list-style:none;}caption,th{text-align:left;}h1,h2,h3,h4,h5,h6{font-size:100%;font-weight:normal;}q:before,q:after{content:'';}abbr,acronym{border:0;font-variant:normal;}sup{vertical-align:baseline;}sub{vertical-align:baseline;}legend{color:#000;}input,button,textarea,select,optgroup,option{font-family:inherit;font-size:inherit;font-style:inherit;font-weight:inherit;}input,button,textarea,select{*font-size:100%;}body{font:13px/1.231 arial,helvetica,clean,sans-serif;*font-size:small;*font:x-small;}select,input,button,textarea,button{font:99% arial,helvetica,clean,sans-serif;}table{font-size:inherit;font:100%;}pre,code,kbd,samp,tt{font-family:monospace;*font-size:108%;line-height:100%;}body{text-align:center;}#doc,#doc2,#doc3,#doc4,.yui-t1,.yui-t2,.yui-t3,.yui-t4,.yui-t5,.yui-t6,.yui-t7{margin:auto;text-align:left;width:57.69em;*width:56.25em;}#doc2{width:73.076em;*width:71.25em;}#doc3{margin:auto 10px;width:auto;}#doc4{width:74.923em;*width:73.05em;}.yui-b{position:relative;}.yui-b{_position:static;}#yui-main .yui-b{position:static;}#yui-main,.yui-g .yui-u .yui-g{width:100%;}.yui-t1 #yui-main,.yui-t2 #yui-main,.yui-t3 #yui-main{float:right;margin-left:-25em;}.yui-t4 #yui-main,.yui-t5 #yui-main,.yui-t6 #yui-main{float:left;margin-right:-25em;}.yui-t1 .yui-b{float:left;width:12.30769em;*width:12.00em;}.yui-t1 #yui-main .yui-b{margin-left:13.30769em;*margin-left:13.05em;}.yui-t2 .yui-b{float:left;width:13.8461em;*width:13.50em;}.yui-t2 #yui-main .yui-b{margin-left:14.8461em;*margin-left:14.55em;}.yui-t3 .yui-b{float:left;width:23.0769em;*width:22.50em;}.yui-t3 #yui-main .yui-b{margin-left:24.0769em;*margin-left:23.62em;}.yui-t4 .yui-b{float:right;width:13.8456em;*width:13.50em;}.yui-t4 #yui-main .yui-b{margin-right:14.8456em;*margin-right:14.55em;}.yui-t5 .yui-b{float:right;width:18.4615em;*width:18.00em;}.yui-t5 #yui-main .yui-b{margin-right:19.4615em;*margin-right:19.125em;}.yui-t6 .yui-b{float:right;width:23.0769em;*width:22.50em;}.yui-t6 #yui-main .yui-b{margin-right:24.0769em;*margin-right:23.62em;}.yui-t7 #yui-main .yui-b{display:block;margin:0 0 1em 0;}#yui-main .yui-b{float:none;width:auto;}.yui-gb .yui-u,.yui-g .yui-gb .yui-u,.yui-gb .yui-g,.yui-gb .yui-gb,.yui-gb .yui-gc,.yui-gb .yui-gd,.yui-gb .yui-ge,.yui-gb .yui-gf,.yui-gc .yui-u,.yui-gc .yui-g,.yui-gd .yui-u{float:left;}.yui-g .yui-u,.yui-g .yui-g,.yui-g .yui-gb,.yui-g .yui-gc,.yui-g .yui-gd,.yui-g .yui-ge,.yui-g .yui-gf,.yui-gc .yui-u,.yui-gd .yui-g,.yui-g .yui-gc .yui-u,.yui-ge .yui-u,.yui-ge .yui-g,.yui-gf .yui-g,.yui-gf .yui-u{float:right;}.yui-g div.first,.yui-gb div.first,.yui-gc div.first,.yui-gd div.first,.yui-ge div.first,.yui-gf div.first,.yui-g .yui-gc div.first,.yui-g .yui-ge div.first,.yui-gc div.first div.first{float:left;}.yui-g .yui-u,.yui-g .yui-g,.yui-g .yui-gb,.yui-g .yui-gc,.yui-g .yui-gd,.yui-g .yui-ge,.yui-g .yui-gf{width:49.1%;}.yui-gb .yui-u,.yui-g .yui-gb .yui-u,.yui-gb .yui-g,.yui-gb .yui-gb,.yui-gb .yui-gc,.yui-gb .yui-gd,.yui-gb .yui-ge,.yui-gb .yui-gf,.yui-gc .yui-u,.yui-gc .yui-g,.yui-gd .yui-u{width:32%;margin-left:1.99%;}.yui-gb .yui-u{*margin-left:1.9%;*width:31.9%;}.yui-gc div.first,.yui-gd .yui-u{width:66%;}.yui-gd div.first{width:32%;}.yui-ge div.first,.yui-gf .yui-u{width:74.2%;}.yui-ge .yui-u,.yui-gf div.first{width:24%;}.yui-g .yui-gb div.first,.yui-gb div.first,.yui-gc div.first,.yui-gd div.first{margin-left:0;}.yui-g .yui-g .yui-u,.yui-gb .yui-g .yui-u,.yui-gc .yui-g .yui-u,.yui-gd .yui-g .yui-u,.yui-ge .yui-g .yui-u,.yui-gf .yui-g .yui-u{width:49%;*width:48.1%;*margin-left:0;}.yui-g .yui-g .yui-u{width:48.1%;}.yui-g .yui-gb div.first,.yui-gb .yui-gb div.first{*margin-right:0;*width:32%;_width:31.7%;}.yui-g .yui-gc div.first,.yui-gd .yui-g{width:66%;}.yui-gb .yui-g div.first{*margin-right:4%;_margin-right:1.3%;}.yui-gb .yui-gc div.first,.yui-gb .yui-gd div.first{*margin-right:0;}.yui-gb .yui-gb .yui-u,.yui-gb .yui-gc .yui-u{*margin-left:1.8%;_margin-left:4%;}.yui-g .yui-gb .yui-u{_margin-left:1.0%;}.yui-gb .yui-gd .yui-u{*width:66%;_width:61.2%;}.yui-gb .yui-gd div.first{*width:31%;_width:29.5%;}.yui-g .yui-gc .yui-u,.yui-gb .yui-gc .yui-u{width:32%;_float:right;margin-right:0;_margin-left:0;}.yui-gb .yui-gc div.first{width:66%;*float:left;*margin-left:0;}.yui-gb .yui-ge .yui-u,.yui-gb .yui-gf .yui-u{margin:0;}.yui-gb .yui-gb .yui-u{_margin-left:.7%;}.yui-gb .yui-g div.first,.yui-gb .yui-gb div.first{*margin-left:0;}.yui-gc .yui-g .yui-u,.yui-gd .yui-g .yui-u{*width:48.1%;*margin-left:0;}.yui-gb .yui-gd div.first{width:32%;}.yui-g .yui-gd div.first{_width:29.9%;}.yui-ge .yui-g{width:24%;}.yui-gf .yui-g{width:74.2%;}.yui-gb .yui-ge div.yui-u,.yui-gb .yui-gf div.yui-u{float:right;}.yui-gb .yui-ge div.first,.yui-gb .yui-gf div.first{float:left;}.yui-gb .yui-ge .yui-u,.yui-gb .yui-gf div.first{*width:24%;_width:20%;}.yui-gb .yui-ge div.first,.yui-gb .yui-gf .yui-u{*width:73.5%;_width:65.5%;}.yui-ge div.first .yui-gd .yui-u{width:65%;}.yui-ge div.first .yui-gd div.first{width:32%;}#hd:after,#bd:after,#ft:after,.yui-g:after,.yui-gb:after,.yui-gc:after,.yui-gd:after,.yui-ge:after,.yui-gf:after{content:".";display:block;height:0;clear:both;visibility:hidden;}#hd,#bd,#ft,.yui-g,.yui-gb,.yui-gc,.yui-gd,.yui-ge,.yui-gf{zoom:1;}

/* Pirum stylesheet */
em { font-style: italic }
strong { font-weight: bold }
small { font-size: 80% }
h1, h2, h3 { font-family:Georgia,Times New Roman,serif; letter-spacing: -0.03em; }
h1 { font-size: 35px; margin-top: 20px; margin-bottom: 30px }
h2 { font-size: 30px; margin-bottom: 20px; margin-top: 15px }
h3 { font-size: 26px; margin-bottom: 10px; margin-top: 20px }
pre { background-color: #000; color: #fff; margin: 5px 0; overflow: auto; padding: 10px; font-family: monospace }
#ft { margin-top: 10px }
ul { margin-top: 5px }
li strong { color: #666 }
p { margin-bottom: 10px }
table { width: 100% }
table th { width: 20% }
table td, table th { padding: 4px; border: 1px solid #ccc }
th { background-color: #eee }

EOF;
        }

        file_put_contents($this->buildDir.'/pirum.css', $content);
    }

    protected function buildIndex()
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Building index.");

        $version = Pirum_CLI::version();

        if (file_exists($file = dirname(__FILE__).'/templates/index.html') ||
            file_exists($file = $this->buildDir.'/templates/index.html')) {
            ob_start();
            include $file;
            $html = ob_get_clean();

            file_put_contents($this->buildDir.'/index.html', $html);

            return;
        }

        ob_start();
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title><?php echo $this->server->summary ?></title>
    <link rel="stylesheet" type="text/css" href="pirum.css" />
    <link rel="alternate" type="application/rss+xml" href="<?php echo $this->server->url ?>/feed.xml" title="<?php echo  $this->server->summary ?> Latest Releases" />
</head>
<body>
    <div id="doc" class="yui-t7">
        <div id="hd">
            <h1><?php echo $this->server->summary ?></h1>
        </div>
        <div id="bd">
            <div class="yui-g">
                <em>Registering</em> the channel:
                <pre><code>pear channel-discover <?php echo $this->server->name ?></code></pre>
                <em>Listing</em> available packages:
                <pre><code>pear remote-list -c <?php echo $this->server->alias ?></code></pre>
                <em>Installing</em> a package:
                <pre><code>pear install <?php echo $this->server->alias ?>/package_name</code></pre>
                <em>Installing</em> a specific version/stability:
                <pre><code>pear install <?php echo $this->server->alias ?>/package_name-1.0.0
pear install <?php echo $this->server->alias ?>/package_name-beta</code></pre>
                <em>Receiving</em> updates via a <a href="<?php echo $this->server->url ?>/feed.xml">feed</a>:
                <pre><code><?php echo $this->server->url ?>/feed.xml</code></pre>

                <?php foreach ($this->packages as $package):
                    $deps = array();
                    if (isset($package['deps']['required']['package'])) {
                        $deps = $package['deps']['required']['package'];
                        if (isset($deps['name'])) {
                            $deps = array($deps);
                        }
                    }

                    $grps = array();
                    if (isset($package['deps']['group'])) {
                        $grps = $package['deps']['group'];
                    }
                ?>
                    <h3><?php echo $package['name'] ?><small> - <?php echo $package['summary'] ?></small></h3>
                    <p><?php echo $package['description'] ?></p>
                    <table>
                        <tr><th>Install command</th><td><strong><?php echo $package['extension'] != null ? 'pecl' : 'pear' ?> install <?php echo $this->server->alias ?>/<?php echo $package['name'] ?></strong></td></tr>
                        <?php if ($grps): ?><?php
                            $groups = array();
                            foreach ($grps as $grp) {
                                $groups[] = ($package['extension'] != null ? 'pecl' : 'pear').' install '.$this->server->alias.'/'.$package['name'].'#'.$grp['attribs']['name'].'&nbsp;<small>('.$grp['attribs']['hint'].')</small>';
                            }
                            $groups = implode('<br/>', $groups);
                        ?>
                        <tr><th>Install groups:</th><td><?php echo $groups ?></td></tr>
                        <?php endif; ?>
                        <tr><th>License</th><td><?php echo $package['license'] ?></td></tr>
                        <?php if ($deps): ?>
                            <tr><th>Dependencies</th><td>
                                <?php for ($i = 0, $count = count($deps); $i < $count; $i++): ?>
                                    <?php echo $deps[$i]['channel'].'/'.$deps[$i]['name'].($i < $count - 1 ? ', ' : '') ?>
                                <?php endfor; ?>
                            </td></tr>
                        <?php endif; ?>
                        <?php
                            $maintainers = array();
                            foreach ($package['current_maintainers'] as $nickname => $maintainer) {
                                $maintainers[] = $maintainer['name'].' <small>(as '.$maintainer['role'].')</small>';
                            }
                            $maintainers = implode(', ', $maintainers);
                        ?>
                        <tr><th>Maintainers</th><td><?php echo $maintainers ?></td></tr>
                        <?php
                        $releases = array();
                        foreach ($package['releases'] as $release) {
                            $releases[] = "<a href=\"{$this->server->url}/get/{$package['name']}-{$release['version']}.tgz\">{$release['version']}</a> <small>({$release['stability']})</small>";
                        }
                        $releases = implode(', ', $releases);
                        ?>
                        <tr><th>Releases</th><td><?php echo $releases ?></td></tr>
                    </table>
                <?php endforeach; ?>
            </div>
        </div>
        <div id="ft">
            <p><small>The <em><?php echo $this->server->name ?></em> PEAR Channel Server is proudly powered by <a href="http://pirum.sensiolabs.org/">Pirum</a> <?php echo $version ?></small></p>
        </div>
    </div>
</body>
</html>
<?php
        $index = ob_get_clean();

        file_put_contents($this->buildDir.'/index.html', $index);
    }

    protected function buildReleasePackages()
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Building releases.");

        mkdir($this->buildDir.'/rest/r', 0777, true);

        foreach ($this->packages as $package) {
            mkdir($dir = $this->buildDir.'/rest/r/'.strtolower($package['name']), 0777, true);

            $this->buildReleasePackage($dir, $package);
        }
    }

    protected function buildReleasePackage($dir, $package)
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Building releases for {$package['name']}.");

        $url = strtolower($package['name']);

        $alpha = '';
        $beta = '';
        $stable = '';
        $snapshot = '';
        $allreleases = '';
        $allreleases2 = '';
        foreach ($package['releases'] as $release) {
            if ('stable' == $release['stability'] && !$stable) {
                $stable = $release['version'];
            } elseif ('beta' == $release['stability'] && !$beta) {
                $beta = $release['version'];
            } elseif ('alpha' == $release['stability'] && !$alpha) {
                $alpha = $release['version'];
            } elseif ('snapshot' == $release['stability'] && !$snapshot) {
                $snapshot = $release['version'];
            }

            $allreleases .= <<<EOF
    <r>
        <v>{$release['version']}</v>
        <s>{$release['stability']}</s>
    </r>

EOF;

            $allreleases2 .= <<<EOF
    <r>
        <v>{$release['version']}</v>
        <s>{$release['stability']}</s>
        <m>{$release['php']}</m>
    </r>

EOF;

            $this->buildRelease($dir, $package, $release);
        }

        if (count($package['releases'])) {
            file_put_contents($dir.'/latest.txt', $package['releases'][0]['version']);
        }

        if ($stable) {
            file_put_contents($dir.'/stable.txt', $stable);
        }

        if ($beta) {
            file_put_contents($dir.'/beta.txt', $beta);
        }

        if ($alpha) {
            file_put_contents($dir.'/alpha.txt', $alpha);
        }

        if ($snapshot) {
            file_put_contents($dir.'/snapshot.txt', $snapshot);
        }

        file_put_contents($dir.'/allreleases.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<a xmlns="http://pear.php.net/dtd/rest.allreleases" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink"     xsi:schemaLocation="http://pear.php.net/dtd/rest.allreleases http://pear.php.net/dtd/rest.allreleases.xsd">
    <p>{$package['name']}</p>
    <c>{$this->server->name}</c>
$allreleases
</a>
EOF
        );

        file_put_contents($dir.'/allreleases2.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<a xmlns="http://pear.php.net/dtd/rest.allreleases2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink"     xsi:schemaLocation="http://pear.php.net/dtd/rest.allreleases2 http://pear.php.net/dtd/rest.allreleases2.xsd">
    <p>{$package['name']}</p>
    <c>{$this->server->name}</c>
$allreleases2
</a>
EOF
        );
    }

    protected function buildRelease($dir, $package, $release)
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Building release {$release['version']} for {$package['name']}.");

        $url = strtolower($package['name']);

        reset($release['maintainers']);
        $maintainer = current($release['maintainers']);

        file_put_contents($dir.'/'.$release['version'].'.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<r xmlns="http://pear.php.net/dtd/rest.release" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.release http://pear.php.net/dtd/rest.release.xsd">
    <p xlink:href="/rest/p/$url">{$package['name']}</p>
    <c>{$this->server->name}</c>
    <v>{$release['version']}</v>
    <st>{$release['stability']}</st>
    <l>{$package['license']}</l>
    <m>{$maintainer['nickname']}</m>
    <s>{$package['summary']}</s>
    <d>{$package['description']}</d>
    <da>{$release['date']}</da>
    <n>{$release['notes']}</n>
    <f>{$release['filesize']}</f>
    <g>{$this->server->url}/get/{$package['name']}-{$release['version']}</g>
    <x xlink:href="package.{$release['version']}.xml"/>
</r>
EOF
        );

        file_put_contents($dir.'/v2.'.$release['version'].'.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<r xmlns="http://pear.php.net/dtd/rest.release2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.release2 http://pear.php.net/dtd/rest.release2.xsd">
    <p xlink:href="/rest/p/$url">{$package['name']}</p>
    <c>{$this->server->name}</c>
    <v>{$release['version']}</v>
    <a>{$release['api_version']}</a>
    <mp>{$release['php']}</mp>
    <st>{$release['stability']}</st>
    <l>{$package['license']}</l>
    <m>{$maintainer['nickname']}</m>
    <s>{$package['summary']}</s>
    <d>{$package['description']}</d>
    <da>{$release['date']}</da>
    <n>{$release['notes']}</n>
    <f>{$release['filesize']}</f>
    <g>{$this->server->url}/get/{$package['name']}-{$release['version']}</g>
    <x xlink:href="package.{$release['version']}.xml"/>
</r>
EOF
        );

        file_put_contents($dir.'/deps.'.$release['version'].'.txt', $release['deps']);

        $release['info']->copyPackageXml($dir."/package.{$release['version']}.xml");
    }

    protected function buildPackages()
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Building packages.");

        mkdir($this->buildDir.'/rest/p', 0777, true);

        $packages = '';
        foreach ($this->packages as $package) {
            $packages .= "  <p>{$package['name']}</p>\n";

            mkdir($dir = $this->buildDir.'/rest/p/'.strtolower($package['name']), 0777, true);
            $this->buildPackage($dir, $package);
        }

        file_put_contents($this->buildDir.'/rest/p/packages.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<a xmlns="http://pear.php.net/dtd/rest.allpackages" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.allpackages http://pear.php.net/dtd/rest.allpackages.xsd">
    <c>{$this->server->name}</c>
$packages
</a>
EOF
        );
    }

    protected function buildPackage($dir, $package)
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Building package {$package['name']}.");

        $url = strtolower($package['name']);

        file_put_contents($dir.'/info.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<p xmlns="http://pear.php.net/dtd/rest.package" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.package    http://pear.php.net/dtd/rest.package.xsd">
<n>{$package['name']}</n>
<c>{$this->server->name}</c>
<ca xlink:href="/rest/c/Default">Default</ca>
<l>{$package['license']}</l>
<s>{$package['summary']}</s>
<d>{$package['description']}</d>
<r xlink:href="/rest/r/{$url}" />
</p>
EOF
        );

        $maintainers = '';
        $maintainers2 = '';
        foreach ($package['current_maintainers'] as $nickname => $maintainer) {
            $maintainers .= <<<EOF
    <m>
        <h>{$nickname}</h>
        <a>{$maintainer['active']}</a>
    </m>

EOF;

            $maintainers2 .= <<<EOF
    <m>
        <h>{$nickname}</h>
        <a>{$maintainer['active']}</a>
        <r>{$maintainer['role']}</r>
    </m>

EOF;
        }

        file_put_contents($dir.'/maintainers.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<m xmlns="http://pear.php.net/dtd/rest.packagemaintainers" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.packagemaintainers http://pear.php.net/dtd/rest.packagemaintainers.xsd">
    <p>{$package['name']}</p>
    <c>{$this->server->name}</c>
$maintainers
</m>
EOF
        );

        file_put_contents($dir.'/maintainers2.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<m xmlns="http://pear.php.net/dtd/rest.packagemaintainers2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.packagemaintainers2 http://pear.php.net/dtd/rest.packagemaintainers2.xsd">
    <p>{$package['name']}</p>
    <c>{$this->server->name}</c>
$maintainers2
</m>
EOF
        );
    }

    protected function buildCategories()
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Building categories.");

        mkdir($this->buildDir.'/rest/c/Default', 0777, true);

        file_put_contents($this->buildDir.'/rest/c/categories.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<a xmlns="http://pear.php.net/dtd/rest.allcategories" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.allcategories http://pear.php.net/dtd/rest.allcategories.xsd">
    <ch>{$this->server->name}</ch>
    <c xlink:href="/rest/c/Default/info.xml">Default</c>
</a>
EOF
        );

        file_put_contents($this->buildDir.'/rest/c/Default/info.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<c xmlns="http://pear.php.net/dtd/rest.category" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.category http://pear.php.net/dtd/rest.category.xsd">
    <n>Default</n>
    <c>{$this->server->name}</c>
    <a>Default</a>
    <d>Default category</d>
</c>
EOF
        );

        $packages = '';
        $packagesinfo = '';
        foreach ($this->packages as $package) {
            $url = strtolower($package['name']);

            $packages .= "  <p xlink:href=\"/rest/p/$url\">{$package['name']}</p>\n";

            $deps = '';
            $releases = '';
            foreach ($package['releases'] as $release) {
                $releases .= <<<EOF
            <r>
                <v>{$release['version']}</v>
                <s>{$release['stability']}</s>
            </r>

EOF;

                $deps .= <<<EOF
        <deps>
            <v>{$release['version']}</v>
            <d>{$release['deps']}</d>
        </deps>

EOF;
            }

            $packagesinfo .= <<<EOF
    <pi>
        <p>
            <n>{$package['name']}</n>
            <c>{$this->server->name}</c>
            <ca xlink:href="/rest/c/Default">Default</ca>
            <l>{$package['license']}</l>
            <s>{$package['summary']}</s>
            <d>{$package['description']}</d>
            <r xlink:href="/rest/r/$url" />
        </p>

        <a>
$releases
        </a>

$deps
    </pi>
EOF;
        }

        file_put_contents($this->buildDir.'/rest/c/Default/packages.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<l xmlns="http://pear.php.net/dtd/rest.categorypackages" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.categorypackages http://pear.php.net/dtd/rest.categorypackages.xsd">
$packages
</l>
EOF
        );

        file_put_contents($this->buildDir.'/rest/c/Default/packagesinfo.xml', <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<f xmlns="http://pear.php.net/dtd/rest.categorypackageinfo" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.categorypackageinfo     http://pear.php.net/dtd/rest.categorypackageinfo.xsd">
$packagesinfo
</f>
EOF
        );
    }

    protected function buildMaintainers()
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Building maintainers.");

        mkdir($dir = $this->buildDir.'/rest/m/', 0777, true);

        $all = '';
        foreach ($this->packages as $package) {
            foreach ($package['maintainers'] as $nickname => $maintainer) {
                $dir = $this->buildDir.'/rest/m/'.$nickname;

                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }

                $info = <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<m xmlns="http://pear.php.net/dtd/rest.maintainer" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.maintainer http://pear.php.net/dtd/rest.maintainer.xsd">
 <h>{$nickname}</h>
 <n>{$maintainer['name']}</n>
 <u>{$maintainer['url']}</u>
</m>
EOF;

                $all .= "  <h xlink:href=\"/rest/m/{$nickname}\">{$nickname}</h>\n";

                file_put_contents($dir.'/info.xml', $info);
            }
        }

        $all = <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<m xmlns="http://pear.php.net/dtd/rest.allmaintainers" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xlink="http://www.w3.org/1999/xlink" xsi:schemaLocation="http://pear.php.net/dtd/rest.allmaintainers http://pear.php.net/dtd/rest.allmaintainers.xsd">
$all
</m>
EOF;

        file_put_contents($this->buildDir.'/rest/m/allmaintainers.xml', $all);
    }

    protected function buildChannel()
    {
        $this->formatter and print $this->formatter->formatSection('INFO', "Building channel.");

        $suggestedalias = '';
        if (!empty($this->server->alias)) {
            $suggestedalias = '
    <suggestedalias>'.$this->server->alias.'</suggestedalias>';
        }

        $validator = '';
        if (!empty($this->server->validatepackage) && !empty($this->server->validateversion)) {
            $validator = '
    <validatepackage version="'.$this->server->validateversion.'">'.$this->server->validatepackage.'</validatepackage>';
        }

        $content = <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<channel version="1.0" xmlns="http://pear.php.net/channel-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://pear.php.net/channel-1.0 http://pear.php.net/dtd/channel-1.0.xsd">
    <name>{$this->server->name}</name>
    <summary>{$this->server->summary}</summary>{$suggestedalias}
    <servers>
        <primary>
            <rest>
                <baseurl type="REST1.0">{$this->server->url}/rest/</baseurl>
                <baseurl type="REST1.1">{$this->server->url}/rest/</baseurl>
                <baseurl type="REST1.2">{$this->server->url}/rest/</baseurl>
                <baseurl type="REST1.3">{$this->server->url}/rest/</baseurl>
            </rest>
        </primary>

EOF;

        foreach ($this->server->mirror as $mirror) {
            $mirror = rtrim($mirror, '/');

            $ssl = '';
            if ('https' === substr($mirror, 0, 5)) {
                $ssl = ' ssl="yes"';
            }

            $content .= <<<EOF
        <mirror host="{$mirror}"{$ssl}>
            <rest>
                <baseurl type="REST1.0">{$mirror}/rest/</baseurl>
                <baseurl type="REST1.1">{$mirror}/rest/</baseurl>
                <baseurl type="REST1.2">{$mirror}/rest/</baseurl>
                <baseurl type="REST1.3">{$mirror}/rest/</baseurl>
            </rest>
        </mirror>

EOF;
        }

        $content .= <<<EOF
    </servers>{$validator}
</channel>
EOF;

        file_put_contents($this->buildDir.'/channel.xml', $content);
    }

    protected function extractInformationFromPackages()
    {
        $this->packages = array();

        // get all package files
        $files = array();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->targetDir.'/get'), RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!preg_match(Pirum_Package::PACKAGE_FILE_PATTERN, $file->getFileName(), $match)) {
                continue;
            }

            $files[$match['release']] = (string) $file;
        }

        // order files to have latest versions first
        uksort($files, 'version_compare');
        $files = array_reverse($files);

        // get information for each package
        $packages = array();
        foreach ($files as $file) {
            $package = new Pirum_Package($file);
            if (file_exists($file = $this->targetDir.'/rest/r/'.strtolower($package->getName()).'/package.'.$package->getVersion().'.xml')) {
                $package->loadPackageFromFile($file);
            } else {
                $package->loadPackageFromArchive();
            }

            $packages[$file] = $package;
        }

        foreach ($packages as $file => $package) {
            $this->formatter and print $this->formatter->formatSection('INFO', sprintf('Parsing package %s for %s.', $package->getVersion(), $package->getName()));

            if ($package->getChannel() != $this->server->name) {
                throw new Exception(sprintf('Package "%s" channel (%s) is not %s.', $package->getName(), $package->getChannel(), $this->server->name));
            }

            if (!isset($this->packages[$package->getName()])) {
                $this->packages[$package->getName()] = array(
                    'name'        => htmlspecialchars($package->getName()),
                    'license'     => htmlspecialchars($package->getLicense()),
                    'summary'     => htmlspecialchars($package->getSummary()),
                    'description' => htmlspecialchars($package->getDescription()),
                    'extension'   => $package->getProvidedExtension(),
                    'releases'    => array(),
                    'maintainers' => array(),
                    'deps'        => unserialize($package->getDeps()),
                    'current_maintainers' => $package->getMaintainers(),
                );
            }

            $this->packages[$package->getName()]['releases'][] = array(
                'version'     => $package->getVersion(),
                'api_version' => $package->getApiVersion(),
                'stability'   => $package->getStability(),
                'date'        => $package->getDate(),
                'filesize'    => $package->getFilesize(),
                'php'         => $package->getMinPhp(),
                'deps'        => $package->getDeps(),
                'notes'       => htmlspecialchars($package->getNotes()),
                'maintainers' => $package->getMaintainers(),
                'info'        => $package,
            );

            $this->packages[$package->getName()]['maintainers'] = array_merge($package->getMaintainers(), $this->packages[$package->getName()]['maintainers']);
        }

        ksort($this->packages);
    }

    protected function mirrorDir($build, $target)
    {
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $this->removeFilesFromDir($target, $build);

        $this->copyFiles($build, $target);
    }

    protected function copyFiles($build, $target)
    {
        $fp = opendir($build);
        while (false !== $file = readdir($fp)) {
            if (in_array($file, array('.', '..'))) {
                continue;
            }

            if (is_dir($build.'/'.$file)) {
                if (!is_dir($target.'/'.$file)) {
                    mkdir($target.'/'.$file, 0777, true);
                }

                $this->copyFiles($build.'/'.$file, $target.'/'.$file);
            } else {
                rename($build.'/'.$file, $target.'/'.$file);
            }
        }
        closedir($fp);
    }

    protected function removeFilesFromDir($target, $build)
    {
        $fp = opendir($target);
        while (false !== $file = readdir($fp)) {
            if (in_array($file, array('.', '..'))) {
                continue;
            }

            if (is_dir($target.'/'.$file)) {
                if (!in_array($file, array('.svn', 'CVS'))) {
                    $this->removeFilesFromDir($target.'/'.$file, $build.'/'.$file);
                    if (!is_dir($build.'/'.$file)) {
                        rmdir($target.'/'.$file);
                    }
                }
            } else {
                unlink($target.'/'.$file);
            }
        }
        closedir($fp);
    }
}

/**
 * Parses a PEAR package and retrieves useful information from it.
 *
 * @package    Pirum
 * @author     Fabien Potencier <fabien@symfony.com>
 */
class Pirum_Package
{
    const PACKAGE_FILE_PATTERN = '#^(?P<release>(?P<name>.+)\-(?P<version>[\d\.]+((?:RC|beta|alpha|dev|snapshot|a|b)\d*)?))\.tgz$#i';

    protected $package;
    protected $tmpDir;
    protected $name;
    protected $version;
    protected $archive;
    protected $packageFile;

    public function __construct($archive)
    {
        $this->archive = $archive;
        if (!preg_match(self::PACKAGE_FILE_PATTERN, $filename = basename($archive), $match)) {
            throw new InvalidArgumentException(sprintf('The archive "%s" does not follow PEAR conventions.', $filename));
        }

        $this->name    = $match['name'];
        $this->version = $match['version'];

        $this->tmpDir = sys_get_temp_dir().'/pirum_package_'.uniqid();

        mkdir($this->tmpDir, 0777, true);
    }

    public function __destruct()
    {
        Pirum_CLI::removeDir($this->tmpDir);
    }

    public function getDate($format = 'Y-m-d H:i:s')
    {
        return date($format, strtotime($this->package->date.' '.$this->package->time));
    }

    public function getLicense()
    {
        return (string) $this->package->license;
    }

    public function getLicenseUri()
    {
        return (string) $this->package->license['uri'];
    }

    public function getDescription()
    {
        return (string) $this->package->description;
    }

    public function getSummary()
    {
        return (string) $this->package->summary;
    }

    public function getChannel()
    {
        return (string) $this->package->channel;
    }

    public function getNotes()
    {
        return (string) $this->package->notes;
    }

    public function getFileSize()
    {
        return filesize($this->archive);
    }

    public function getApiVersion()
    {
        return (string) $this->package->version->api;
    }

    public function getApiStability()
    {
        return (string) $this->package->stability->api;
    }

    public function getStability()
    {
        return (string) $this->package->stability->release;
    }

    public function getMaintainers()
    {
        $maintainers = array();
        foreach ($this->package->lead as $lead) {
            $maintainers[(string) $lead->user] = array(
                'nickname' => (string) $lead->user,
                'role'     => 'lead',
                'email'    => (string) $lead->email,
                'name'     => (string) $lead->name,
                'url'      => (string) $lead->url,
                'active'   => strtolower((string) $lead->active) == 'yes' ? 1 : 0,
            );
        }

        foreach ($this->package->developer as $developer) {
            $maintainers[(string) $developer->user] = array(
                'nickname' => (string) $developer->user,
                'role'     => 'developer',
                'email'    => (string) $developer->email,
                'name'     => (string) $developer->name,
                'url'      => (string) $developer->url,
                'active'   => strtolower((string) $developer->active) == 'yes' ? 1 : 0,
            );
        }

        return $maintainers;
    }

    public function getDeps()
    {
        $deps = $this->XMLToArray($this->package->dependencies);
        if ($this->package->dependencies->group->count() > 0) {
            $deps['group'] = $this->XMLGroupDepsToArray($this->package->dependencies->group);
        }

        return serialize($deps);
    }

    public function getMinPhp()
    {
        return isset($this->package->dependencies->required->php->min) ? (string) $this->package->dependencies->required->php->min : null;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function getProvidedExtension() {
        return isset($this->package->providesextension) ? (string)$this->package->providesextension : null;
    }

    public function copyPackageXml($target)
    {
        copy($this->packageFile, $target);
    }

    public function loadPackageFromFile($file)
    {
        $this->packageFile = $file;

        $this->package = new SimpleXMLElement(file_get_contents($file));

        // check name
        if ($this->name != (string) $this->package->name) {
            throw new InvalidArgumentException(sprintf('The package.xml name "%s" does not match the name of the archive file "%s".', $this->package->name, $this->name));
        }

        // check version
        if ($this->version != (string) $this->package->version->release) {
            throw new InvalidArgumentException(sprintf('The package.xml version "%s" does not match the version of the archive file "%s".', $this->package->version->release, $this->version));
        }
    }

    public function loadPackageFromArchive()
    {
        if (!function_exists('gzopen')) {
            copy($this->archive, $this->tmpDir.'/archive.tgz');
            system('cd '.$this->tmpDir.' && tar zxpf archive.tgz');

            if (!is_file($this->tmpDir.'/package.xml')) {
                throw new InvalidArgumentException('The PEAR package does not have a package.xml file.');
            }

            $this->loadPackageFromFile($this->tmpDir.'/package.xml');

            return;
        }

        $gz = gzopen($this->archive, 'r');
        $tar = '';
        while (!gzeof($gz)) {
            $tar .= gzread($gz, 10000);
        }
        gzclose($gz);

        while (strlen($tar)) {
            $filename = rtrim(substr($tar, 0, 100), chr(0));
            $filesize = octdec(rtrim(substr($tar, 124, 12), chr(0)));

            if ($filename != 'package.xml') {
                $offset = $filesize % 512 == 0 ? $filesize : $filesize + (512 - $filesize % 512);
                $tar = substr($tar, 512 + $offset);

                continue;
            }

            $checksum = octdec(rtrim(substr($tar, 148, 8), chr(0)));
            $cchecksum = 0;
            $tar = substr_replace($tar, '        ', 148, 8);
            for ($i = 0; $i < 512; $i++) {
                $cchecksum += ord($tar[$i]);
            }

            if ($checksum != $cchecksum) {
                throw new InvalidArgumentException('The PEAR archive is not a valid archive.');
            }

            $package = substr($tar, 512, $filesize);
            $this->packageFile = $this->tmpDir.'/package.xml';

            file_put_contents($this->packageFile, $package);

            $this->loadPackageFromFile($this->tmpDir.'/package.xml');

            return;
        }

        throw new InvalidArgumentException('The PEAR package does not have a package.xml file.');
    }

    protected function XMLToArray($xml)
    {
        $array = array();
        foreach ($xml->children() as $element => $value) {
            $key = (string) $element;
            $value = count($value->children()) ? $this->XMLToArray($value) : (string) $value;

            if (array_key_exists($key, $array)) {
                if (!isset($array[$key][0])) {
                    $array[$key] = array($array[$key]);
                }
                $array[$key][] = $value;
            } else {
                $array[$key] = $value;
            }
        }

        return $array;
    }

    protected function XMLGroupDepsToArray($xml)
    {
        if ($xml->count() == 0) {
            return array();
        }

        $deps = array();
        foreach ($xml as $group) {
            // Extract group attribs (shared by all children)
            $attribs = array();
            foreach ($group->attributes() as $key => $val) {
                $attribs[$key] = (string) $val;
            }

            // Create one group dependency per childern
            foreach ($group->children() as $type => $value) {
                $deps[] = array('attribs' => $attribs, $type => $this->XMLToArray($value));
            }
        }

        return $deps;
    }
}

/**
 * Command line colorizer for Pirum.
 *
 * @package    Pirum
 * @author     Fabien Potencier <fabien@symfony.com>
 */
class Pirum_CLI_Formatter
{
    protected $styles = array(
        'ERROR_SECTION'   => array('bg' => 'red', 'fg' => 'white'),
        'INFO_SECTION'    => array('bg' => 'green', 'fg' => 'white'),
        'COMMENT_SECTION' => array('bg' => 'yellow', 'fg' => 'white'),
        'ERROR'           => array('fg' => 'red'),
        'INFO'            => array('fg' => 'green'),
        'COMMENT'         => array('fg' => 'yellow'),
    );
    protected $options    = array('bold' => 1, 'underscore' => 4, 'blink' => 5, 'reverse' => 7, 'conceal' => 8);
    protected $foreground = array('black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34, 'magenta' => 35, 'cyan' => 36, 'white' => 37);
    protected $background = array('black' => 40, 'red' => 41, 'green' => 42, 'yellow' => 43, 'blue' => 44, 'magenta' => 45, 'cyan' => 46, 'white' => 47);
    protected $supportsColors;

    public function __construct()
    {
        $this->supportsColors = DIRECTORY_SEPARATOR != '\\' && function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }

    /**
     * Formats a text according to the given style or parameters.
     *
     * @param  string   $text  The text to style
     * @param  string   $style A style name
     *
     * @return string The styled text
     */
    public function format($text = '', $style = 'NONE')
    {
        if (!$this->supportsColors) {
            return $text;
        }

        if ('NONE' == $style || !isset($this->styles[$style])) {
            return $text;
        }

        $parameters = $this->styles[$style];

        $codes = array();
        if (isset($parameters['fg'])) {
            $codes[] = $this->foreground[$parameters['fg']];
        }
        if (isset($parameters['bg'])) {
            $codes[] = $this->background[$parameters['bg']];
        }
        foreach ($this->options as $option => $value) {
            if (isset($parameters[$option]) && $parameters[$option]) {
                $codes[] = $value;
            }
        }

        return "\033[".implode(';', $codes).'m'.$text."\033[0m";
    }

    /**
     * Formats a message within a section.
     *
     * @param string  $section  The section name
     * @param string  $text     The text message
     */
    public function formatSection($section, $text)
    {
        $section = $style = array_key_exists($section, $this->styles) ? $section : 'INFO';
        $section = " $section ".str_repeat(' ', max(0, 5 - strlen($section)));
        $style .= '_SECTION';

        return sprintf("  %s %s\n", $this->format($section, $style), $text);
    }
}