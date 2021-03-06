<?php
/**
 * Discuss
 *
 * Copyright 2010-11 by Shaun McCormick <shaun@modx.com>
 *
 * This file is part of Discuss, a native forum for MODx Revolution.
 *
 * Discuss is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * Discuss is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * Discuss; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package discuss
 */
/**
 * Handles all basic Discuss request handling within the forums.
 *
 * @package discuss
 * @subpackage request
 */
class DisRequest {
    /**
     * The default controller for the request
     * @var string $controller
     */
    public $controller = 'home';
    /**
     * Any page-specific options for the loaded controller
     * @var array $pageOptions
     */
    public $pageOptions = array();

    /**
     * @param Discuss $discuss A reference to the Discuss instance
     * @param array $config An array of configuration properties
     */
    function __construct(Discuss &$discuss,array $config = array()) {
        $this->discuss =& $discuss;
        $this->modx =& $discuss->modx;
        $this->config = array_merge(array(
            'actionVar' => 'action',
        ),$config);
    }

    /**
     * Get the controller to load
     * 
     * @return string The controller to load
     */
    public function getControllerValue() {
        $controller = !empty($_REQUEST[$this->config['actionVar']]) ? $_REQUEST[$this->config['actionVar']] : 'home';
        $controller = str_replace(array('../','./'),'',$controller);
        $colon = strpos($controller,';');
        if ($colon !== false) {
            $controller = substr($controller,0,$colon);
        }
        $controller = strip_tags(trim($controller,'/'));
        $c = $this->getControllerFile($controller);
        $this->controller = $c;
        return $c['controller'];
    }

    /**
     * Handle the current Discuss request
     * 
     * @return string The output HTML
     */
    public function handle() {
        $this->getControllerValue();
        $this->loadThemeOptions();
        return $this->render();
    }

    /**
     * Render the request using the loaded controller
     * @return string
     */
    public function render() {
        $controller = $this->controller;

        if (!$this->modx->loadClass('DiscussController',$this->discuss->config['modelPath'].'discuss/',true,true)) {
            return '';
        }

        if (empty($controller['isClass'])) {
            $className = 'DiscussDeprecatedController';
        } else {
            $className = $this->getControllerClassName();
        }

        $output = '';
        if (file_exists($controller['file'])) {
            if (!empty($controller['isClass'])) {
                require_once $controller['file'];
            }
            $c = new $className($this->discuss,$this->controller);
            
            $this->discuss->controller = call_user_func_array(array($c,'getInstance'),array($this->discuss,$className,$this->controller));

            $this->discuss->controller->scriptProperties = array_merge($_REQUEST,$_GET,$_POST);
            $this->discuss->controller->setOptions($this->pageOptions);
            $output = $this->discuss->controller->render();
            
        } else {
            $this->discuss->sendErrorPage();
        }
        return $output;
    }

    /**
     * Get the processed name of the controller to load
     * @return string
     */
    public function getControllerClassName() {
        $className = 'Discuss'.ucfirst(strtolower($this->controller['controller'])).'Controller';
        $className = explode('/',$className);
        $o = array();
        foreach ($className as $k) {
            if (strpos($k,'_')) {
                $substr = '';
                $e = explode('_',$k);
                foreach ($e as $ex) {
                    $substr[] = ucfirst($ex);
                }
                $k = implode('',$substr);
            }
            $o[] = ucfirst(str_replace(array('.','_','-'),'',$k));
        }
        return implode('',$o);
    }
    /**
     * Return the file path to the specified controller
     * @param string $controller
     * @return array An array of file location, template location, and controller name information
     */
    public function getControllerFile($controller = 'home') {
        $controllerFile = $this->discuss->config['controllersPath'].'web/'.$controller;
        $controllerArray = array(
            'isClass' => false,
            'tpl' => $this->discuss->config['pagesPath'].strtolower($controller).'.tpl',
            'controller' => $controller,
        );

        if (file_exists($controllerFile.'.class.php')) {
            $controllerArray['isClass'] = true;
            $controllerArray['file'] = $controllerFile.'.class.php';
        } else if (file_exists($controllerFile.'/index.class.php')) {
            $controllerArray['isClass'] = true;
            $controllerArray['file'] = $controllerFile.'/index.class.php';
            $controllerArray['tpl'] = $this->discuss->config['pagesPath'].strtolower($controller).'/index.tpl';
        } else if (!file_exists($controllerFile.'.php') && file_exists($controllerFile.'/index.php')) {
            $controllerArray['file'] = $controllerFile.'/index.php';
            $controllerArray['controller'] .= '/index';
            $controllerArray['tpl'] = $this->discuss->config['pagesPath'].strtolower($controller).'/index.tpl';
        } else {
            $controllerArray['file'] = $controllerFile.'.php';
        }
        return $controllerArray;
    }

	/**
     * Load current theme options for Front end
     *
     * @access public
     * @return void
     */
	public function loadThemeOptions() {
        $additional = $this->controller['controller'];
        
        $f = $this->discuss->config['themePath'].'manifest.php';
        if (file_exists($f)) {
            $manifest = require $f;

            if (is_array($manifest) && array_key_exists('print', $manifest) && !empty($_REQUEST['print'])) {
                $print = $manifest['print'];

				/* Load global print CSS */
				if (array_key_exists('css', $print)){
					$css = $print['css'];
					foreach($css['header'] as $val){
						$this->modx->regClientCSS($this->discuss->config['cssUrl'].$val);
					}
				}

				/* load global print page options */
				if (array_key_exists('options', $print)){
					$this->pageOptions = array_merge($this->pageOptions,$print['options']);
                }

            } else if (is_array($manifest) && array_key_exists('global', $manifest)){
				$global = $manifest['global'];

				/* Load global forum CSS */
				if(array_key_exists('css', $global)){
					$css = $global['css'];
					foreach($css['header'] as $val){
						$this->modx->regClientCSS($this->discuss->config['cssUrl'].$val);
					}
				}

				/* Load global forum JS */
				if(array_key_exists('js', $global)){
					$js = $global['js'];
					foreach($js['header'] as $val){
						$this->modx->regClientStartupScript($this->discuss->config['jsUrl'].$val);
					}
					if(isset($js['inline'])){
						$this->modx->regClientStartupHTMLBlock('<script type="text/javascript">// <![CDATA['."\n".$js['inline']."\n".'// ]]></script>');
					}
				}

				/* load global page options */
				if (array_key_exists('options', $global)){
					$this->pageOptions = array_merge($this->pageOptions,$global['options']);
                }
			}

			if (isset($additional) && is_array($manifest) && array_key_exists($additional, $manifest)){
				$specific = $manifest[$additional];

				/* Load specific forum CSS */
				if(array_key_exists('css', $specific)){
					$css = $specific['css'];
					foreach($css['header'] as $val){
						$this->modx->regClientCSS($this->discuss->config['cssUrl'].$val);
					}
				}

				/* Load specific forum JS */
				if(array_key_exists('js', $specific)){
					$js = $specific['js'];
					foreach($js['header'] as $val){
						$this->modx->regClientStartupScript($this->discuss->config['jsUrl'].$val);
					}
					if(isset($js['inline'])){
						$this->modx->regClientHTMLBlock('<script type="text/javascript">'.$js['inline'].'</script>');
					}
				}

				/* load page-specific options */
				if (array_key_exists('options', $specific)){
					$this->pageOptions = array_merge($this->pageOptions,$specific['options']);
                }
			}
        } else {
            $this->modx->regClientCSS($this->discuss->config['cssUrl'].'index.css');
            $this->modx->regClientStartupScript($this->discuss->config['jsUrl'].'web/discuss.js');
        }
	}

    /**
     * Makes a proper URL for the Discuss system
     *
     * @param string $action
     * @param array $params
     * @return string
     */
    public function makeUrl($action = '',array $params = array()) {
        if (is_array($params)) {
            $params = http_build_query($params);
            if (!empty($params)) $params = '?'.$params;
        }
        $url = $this->discuss->url.$action.$params;
        if ($this->modx->getOption('discuss.absolute_urls',null,true)) {
            $url = $this->modx->getOption('site_url',null,MODX_SITE_URL).ltrim($url,'/');
        }
        return $url;
    }
    
}