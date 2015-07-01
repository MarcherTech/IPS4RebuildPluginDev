<?php

/**
 * @brief		Rebuild Plugin Dev
 * @author		<a href='http://www.marchertech.com'>Marcher Technologies</a>
 */

namespace IPS;

/* Check this is running at the command line */
if (\php_sapi_name() !== 'cli') {
    echo 'Not at command line' . \PHP_EOL;
    exit;
}
/* Init IPS Suite */
require_once 'init.php';
if (!IN_DEV) {
    echo 'IN_DEV must be enabled to use this tool' . \PHP_EOL;
    exit;
}
new RebuildPluginDev;

class RebuildPluginDev {

    protected $stdin,
            $base;

    public function __construct() {
        $this->stdin = \fopen('php://stdin', 'r');
        $this->base = ROOT_PATH . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR;
        $this->_print('--------------------------------------------');
        $this->_print('Welcome to the IPS4 Rebuild Plugin Dev Tool');
        $this->_print('--------------------------------------------');
        $this->run();
        exit;
    }

    protected function run() {
        $plugin = $this->getPlugin();
        switch ((int) $this->whatToDo()) {
            case 1:
                $this->renameDirectory($plugin);
                break;
            case 2:
                $this->renameHookFile($plugin);
                break;
            case 3:
                $this->writeDevFromDb($plugin);
                break;
            case 4:
                $this->writeDevFromXml($plugin);
                break;
        }
        $this->run();
    }

    protected function getPlugin() {
        $this->_print('Select a plugin:');
        foreach (Db::i()->select(array('plugin_id', 'plugin_name', 'plugin_location'), 'core_plugins', array('plugin_enabled=?', 1), 'plugin_order ASC')->setKeyField('plugin_id') as $idx => $plug) {
            $this->_print('[' . $idx . '] ' . $plug['plugin_name'] . ' (' . $plug['plugin_location'] . ')');
        }
        $plugin = $this->fetchOption();
        try {
            $plugin = Plugin::load((int) $plugin);
        } catch (\OutOfRangeException $ex) {
            $this->_print('Invalid Selection!');
            $plugin = $this->getPlugin();
        }
        return $plugin;
    }

    protected function whatToDo() {
        $this->_print('Select an action:');
        $this->_print('[1] Change Plugin Directory/Namespace');
        $this->_print('[2] Change Plugin Hook Filenames');
        $this->_print('[3] Recreate Dev - html, css, js, lang.php, widgets.json');
        $this->_print('[4] Recreate Dev - resources, setup, versions.json');
        return $this->fetchOption();
    }

    protected function renameDirectory(Plugin $plugin) {
        $namespace = $this->getNamespace();
        if (\is_dir($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR)) {
            foreach (new \DirectoryIterator($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR) as $file) {
                if ($file->isDot() || \strpos($file->getFilename(), '.php') === FALSE) {
                    continue;
                }
                \file_put_contents($file->getPathname(), \str_replace('namespace IPS\plugins\\' . $plugin->location . '\widgets;', 'namespace IPS\plugins\\' . $namespace . '\widgets;', \file_get_contents($file->getPathname())));
            }
        }
        $this->cloneFolder($this->base . $plugin->location . DIRECTORY_SEPARATOR, $this->base . $namespace . DIRECTORY_SEPARATOR);
        \IPS\DB::i()->update('core_plugins', array('plugin_location' => $namespace), array('plugin_id=?', $plugin->id));
        \IPS\Plugin\Hook::writeDataFile();
    }

    protected function getNamespace() {
        $this->_print("Input original/desired plugin namespace/directory:");
        $ns = $this->fetchOption();
        if (!$ns) {
            $this->_print('Invalid Input!');
            $ns = $this->getNamespace();
        }
        return $ns;
    }

    protected function cloneFolder($source, $destination) {
        @\mkdir($destination, IPS_FOLDER_PERMISSION);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source)) as $resource) {
            if ($resource->isDir()) {
                @\mkdir(\str_replace($source, $destination, $resource->getPathname()), IPS_FOLDER_PERMISSION);
            } else {
                \copy($resource->getPathname(), \str_replace($source, $destination, $resource->getPathname()));
            }
        }
    }

    protected function renameHookFile(Plugin $plugin) {
        $hook = $this->getHook($plugin);
        $name = $this->getHookFilename();
        @\unlink($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . $name . '.php');
        \rename($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . $hook->filename . '.php', $this->base . $plugin->location . DIRECTORY_SEPARATOR . 'hooks' . DIRECTORY_SEPARATOR . $name . '.php');
        DB::i()->update('core_hooks', array('filename' => $name), array('id=?', $hook->id));
        \IPS\Plugin\Hook::writeDataFile();
    }

    protected function getHook(Plugin $plugin) {
        $this->_print('Select a hook:');
        foreach (Db::i()->select(array('id', 'filename', 'class'), 'core_hooks', array('plugin=?', (int) $plugin->id))->setKeyField('id') as $idx => $hook) {
            $this->_print('[' . $idx . '] ' . $hook['filename'] . ' (' . $hook['class'] . ')');
        }
        unset($hook);
        $hook = $this->fetchOption();
        try {
            $hook = \IPS\Plugin\Hook::load((int) $hook);
            if (!$hook->plugin || $hook->plugin !== $plugin->id) {
                throw new \OutOfRangeException;
            }
        } catch (\OutOfRangeException $ex) {
            $this->_print('Invalid Selection!');
            $hook = $this->getHook($plugin);
        }
        return $hook;
    }

    protected function getHookFilename() {
        $this->_print("Input original/desired hook filename:");
        $name = $this->fetchOption();
        if (!$name) {
            $this->_print('Invalid Input!');
            $name = $this->getHookFilename();
        }
        return $name;
    }

    protected function writeDevFromDb(Plugin $plugin) {
        $base = $this->base . $plugin->location . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR;
        $this->writeDevHTML($plugin, $base);
        $this->writeDevCSS($plugin, $base);
        $this->writeDevJS($plugin, $base);
        $this->writeDevLang($plugin, $base);
        $this->writeDevWidgetJSON($plugin, $base);
    }

    protected function writeDevHTML(Plugin $plugin, $base) {
        @\mkdir($base . 'html' . DIRECTORY_SEPARATOR, IPS_FOLDER_PERMISSION, TRUE);
        foreach (Db::i()->select(array('template_name', 'template_data', 'template_content'), 'core_theme_templates', array('template_set_id=? AND template_plugin=?', 0, (int) $plugin->id))->setKeyField('template_name') as $name => $template) {
            $file = $base . 'html' . DIRECTORY_SEPARATOR . $name . '.phtml';
            $content = <<<IPSHTML
<ips:template parameters="{$template['template_data']}" />
{$template['template_content']}
IPSHTML;
            \file_put_contents($file, $content);
        }
    }

    protected function writeDevCSS(Plugin $plugin, $base) {
        @\mkdir($base . 'css' . DIRECTORY_SEPARATOR, IPS_FOLDER_PERMISSION);
        foreach (Db::i()->select(array('css_name', 'css_content'), 'core_theme_css', array('css_name=? AND css_plugin=?', 0, (int) $plugin->id))->setKeyField('css_name')->setValueField('css_content') as $name => $css) {
            \file_put_contents($base . 'css' . DIRECTORY_SEPARATOR . $name, $css);
        }
    }

    protected function writeDevJS(Plugin $plugin, $base) {
        @\mkdir($base . 'js' . DIRECTORY_SEPARATOR, IPS_FOLDER_PERMISSION);
        foreach (Db::i()->select(array('javascript_name', 'javascript_content'), 'core_javascript', array('javascript_plugin=?', (int) $plugin->id))->setKeyField('javascript_name')->setValueField('javascript_content') as $name => $js) {
            \file_put_contents($base . 'js' . DIRECTORY_SEPARATOR . $name, $js);
        }
    }

    protected function writeDevLang(Plugin $plugin, $base) {
        $lang = array();
        foreach (Db::i()->select(array('word_key', 'word_default'), 'core_sys_lang_words', array('lang_id=? AND word_plugin=?', (int) Lang::defaultLanguage(), (int) $plugin->id))->setKeyField('word_key')->setValueField('word_default') as $key => $word) {
            $lang[$key] = $word;
        }
        \file_put_contents($base . 'lang.php', '<?php' . \PHP_EOL . '$lang = ' . \var_export($lang, TRUE));
    }

    protected function writeDevWidgetJSON(Plugin $plugin, $base) {
        $json = array();
        foreach (Db::i()->select(array('key', 'class', 'restrict', 'default_area', 'allow_reuse', 'menu_style', 'embeddable'), 'core_widgets', array('plugin=?', (int) $plugin->id))->setKeyField('key') as $key => $widget) {
            unset($widget['key']);
            $json[$key] = \array_merge($widget, array('restrict' => \json_decode($widget['restrict'])));
        }
        \file_put_contents($base . 'widgets.json', \json_encode($json));
    }

    protected function writeDevFromXml(Plugin $plugin) {
        $name = $this->wantCustomXmlName() ? $this->getCustomXmlName() : $plugin->name;
        $file = $this->base . $plugin->location . DIRECTORY_SEPARATOR . $name . '.xml';
        $versions = array();
        if (\is_file($file) && \is_readable($file)) {
            @mkdir($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR, IPS_FOLDER_PERMISSION, TRUE);
            @mkdir($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR, IPS_FOLDER_PERMISSION);
            $xml = new \XMLReader;
            $xml->open($file);
            $xml->read();
            $xml->read();
            while ($xml->read()) {
                if ($xml->nodeType != \XMLReader::ELEMENT || !in_array($xml->name, array('resources', 'version'))) {
                    continue;
                }
                if ($xml->name == 'resources') {
                    \file_put_contents($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . $xml->getAttribute('filename'), \base64_decode($xml->readString()));
                    continue;
                }
                $versions[$xml->getAttribute('long')] = $xml->getAttribute('human');
                if (!\file_exists($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'install.php')) {
                    \file_put_contents($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'install.php', $xml->readString());
                } else {
                    \file_put_contents($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . $xml->getAttribute('long') . '.php', $xml->readString());
                }
            }
            \file_put_contents($this->base . $plugin->location . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'versions.json', \json_encode($versions));
        } else {
            $this->_print('Invalid xml filename input, xml file is missing, or xml file is not readable.');
            $this->writeDevFromXml($plugin);
        }
    }

    protected function wantCustomXmlName() {
        $this->_print('Please upload or copy plugin xml to plugin folder.');
        $this->_print('Does the plugin xml filename differ from the plugin name?');
        $opt = $this->fetchOption();
        return (!(int) $opt || $opt === 'n') ? FALSE : TRUE;
    }

    protected function getCustomXmlName() {
        $this->_print("Input plugin xml filename(without .xml):");
        $name = $this->fetchOption();
        if (!$name) {
            $this->_print('Invalid Input!');
            $name = $this->getCustomXmlName();
        }
        return $name;
    }

    /**
     * Out to stdout
     */
    protected function _print($message, $newline = \PHP_EOL) {
        $stdout = \fopen('php://stdout', 'w');
        \fwrite($stdout, $message . $newline);
        \fclose($stdout);
    }

    /* Fetch option
     *
     */

    protected function _fetchOption() {
        return \trim(\fgets($this->stdin));
    }

    /* Fetch option wrapper
     *
     */

    protected function fetchOption() {
        $opt = $this->_fetchOption();
        if ($opt === 'x') {
            print 'Goodbye!';
            exit;
        }
        return $opt;
    }

}
