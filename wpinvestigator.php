<?php
/*
Plugin Name: WP Investigator
Plugin URI: https://michaelblouin.ca
Description: Investigate what is making your site slow.
Version: 1.0.0
Author: Michael Blouin
Author URI: https://michaelblouin.ca
License: Apache 2.0
Text Domain: michaelblouin-wpinvestigator
Domain Path: /languages/
*/

if (!class_exists('WPInvestigator')) {
    class WPInvestigator {
        private $intercept_priorities = array();
        private $previously_defined_scripts = array();
        private $script_defines = array();

        function add_actions_filters() {
            add_action('shutdown', array($this, 'do_shutdown_action'));
            add_action('wp_loaded', array($this, 'wp_loaded_action'));
        }

        function _write_call_data_json($filename) {
            file_put_contents($filename, json_encode(array(
                'script_defines' => $this->script_defines,
            ), JSON_PRETTY_PRINT));
        }
        
        function do_shutdown_action() {
            $this->_write_call_data_json(dirname(__FILE__) . '/debug.txt');
        }
        
        /**
         * 
         * @param ReflectionFunction $function
         */
        function infer_source($function) {
            $path = $function->getFileName();
            
            if (substr($path, 0, strlen(WP_PLUGIN_DIR)) === WP_PLUGIN_DIR) {
                // This is a plugin
                return array( 'type' => 'plugin', 'is_wp_internal' => false );
            }
            
            if (substr($path, 0, strlen(get_theme_root())) === get_theme_root()) {
                // This is a theme
                $theme_name = array();
                
                if (preg_match('/[^\\/\\\\]+/', substr($path, strlen(get_theme_root())), $theme_name)) {
                    $theme = wp_get_theme($theme_name[0]);
                    if (null !== $theme) {
                        return array(
                            'type' => 'theme',
                            'name' => $theme->get('Name'),
                            'version' => $theme->get('Version'),
                            'author' => $theme->display('Author'),
                            'is_wp_internal' => false,
                        );
                    }
                }
            }
            
            return array( 'type' => 'unknown' );
        }
        
        function wp_loaded_action() {
            global $wp_filter;
            $old_enqueue_scripts = $wp_filter['wp_enqueue_scripts'];
            $wp_filter['wp_enqueue_scripts'] = array();
            
            // Add the initial grabber
            add_action('wp_enqueue_scripts', array( new WPInvestigatorScriptAction($this, true), 'enqueue_scripts_action' ), 0);
            
            foreach ($old_enqueue_scripts as $priority => $items) {
                foreach ($items as $idx => $item) {
                    $this->intercept_priorities[] = array($priority, $idx);
                    $wp_filter['wp_enqueue_scripts'][$priority][$idx] = $item;
                    add_action('wp_enqueue_scripts', array( new WPInvestigatorScriptAction($this, false), 'enqueue_scripts_action' ), $priority);
                }
            }
            
            reset( $this->intercept_priorities );
        }
        
        function add_script_define($define) {
            $this->script_defines[] = $define;
        }
        
        function get_current_intercept_priority() {
            $priority = current( $this->intercept_priorities );
            next( $this->intercept_priorities );
            return $priority;
        }
        
        function add_previously_defined_script($name, $info) {
            $this->previously_defined_scripts[$name] = $info->src;
        }
        
        function is_previously_defined_script($name) {
            return array_key_exists($name, $this->previously_defined_scripts);
        }
    }
    
    class WPInvestigatorScriptAction {
        /**
         *
         * @var WPInvestigator
         */
        private $investigator = null;
        private $is_first_iter = false;

        public function __construct(&$investigator, $first) {
            $this->investigator = $investigator;
            $this->is_first_iter = $first;
        }
        
        function enqueue_scripts_action() {
            global $wp_filter, $wp_scripts;

            if ($this->is_first_iter) {
                // Grab the list of scripts not registered by a discernible function
                $this->is_first_iter = false;
                foreach ($wp_scripts->registered as $name => $info) {
                    $this->investigator->add_previously_defined_script($name, $info);
                }
            } else {
                $priority = $this->investigator->get_current_intercept_priority();
                
                if (false === $priority) {
                    return;
                }
                
                foreach ($wp_scripts->registered as $name => $info) {
                    if (!$this->investigator->is_previously_defined_script($name)) {
                        // New script was registered by the last action
                        $this->investigator->add_previously_defined_script($name, $info);
                        $item = $wp_filter['wp_enqueue_scripts'][$priority[0]][$priority[1]];
                        
                        $definition = array(
                            'idx' => $name,
                            'definition' => $item, 
                            'script_name' => $name,
                            'script_info' => $info,
                        );
                        
                        $this->add_source_info($definition, $item);
                        
                        $this->investigator->add_script_define($definition);
                    }
                }
            }
        }
        
        private function add_source_info(&$definition, &$item) {
            if (is_array($item['function'])) {
                $class = new ReflectionClass($item['function'][0]);
                $method = $class->getMethod($item['function'][1]);
                $definition['source_info'] = $this->investigator->infer_source($method);
                $definition['source'] = array(
                    'name' => $method->getShortName(),
                    'fileName' => $method->getFileName(),
                    'line' => $method->getStartLine(),
                );
            } elseif (array_key_exists('function', $item)) {
                $func = new ReflectionFunction($item['function']);
                $definition['source_info'] = $this->investigator->infer_source($func);
                $definition['source'] = array(
                    'name' => $func->getShortName(),
                    'fileName' => $func->getFileName(),
                    'line' => $func->getStartLine(),
                );
            } elseif (false) {
                
            }
        }
    }
    
    global $WPInvestigator;
    $WPInvestigator = new WPInvestigator();
    $WPInvestigator->add_actions_filters();
}