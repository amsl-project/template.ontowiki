<?php
/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2013, {@link http://aksw.org AKSW}
 * @license   http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * Plugin to provide template view
 *
 * @category   OntoWiki
 * @package    Extensions_Template
 */
class TemplatePlugin extends OntoWiki_Plugin
{
    // Set default values (can be overwritten in doap.n3)
    protected $_templateUri           = 'http://ns.ontowiki.net/SysOnt/Template';
    protected $_providedPropertiesUri = 'http://ns.ontowiki.net/SysOnt/providesProperty';
    protected $_optionalPropertiesUri = 'http://ns.ontowiki.net/SysOnt/optionalProperty';
    protected $_bindsClassUri         = 'http://ns.ontowiki.net/SysOnt/bindsClass';

    protected $_model;
    protected $_titleHelper;

    public function init()
    {
        parent::init();

        // Overwrite URIs with values from doap.n3
        if (isset($this->_privateConfig->template->templateUri)) {
            $this->_templateUri = $this->_privateConfig->template->templateUri;
        }

        if (isset($this->_privateConfig->template->providedPropertiesUri)) {
            $this->_providedPropertiesUri = $this->_privateConfig->template->providedPropertiesUri;
        }

        if (isset($this->_privateConfig->template->optionalPropertiesUri)) {
            $this->_optionalPropertiesUri = $this->_privateConfig->template->optionalPropertiesUri;
        }

        if (isset($this->_privateConfig->template->bindsClassUri)) {
            $this->_bindsClassUri = $this->_privateConfig->template->bindsClassUri;
        }
    }

    /**
     * This event can be triggered to manipulate the predicates object returned from 
     * getPredicates() method for a resource. The method will search for existing 
     * templates with help of the rdf:type of the resource and will remove the
     * properties that are not provided by the found tempaltes.
     */ 
    public function onPropertiesActionTemplate($event)
    {
        $store  = Erfurt_App::getInstance()->getStore();
        $config = Erfurt_App::getInstance()->getConfig();

        $this->_model       = $event->selectedModel;
        $graph              = $event->graph;
        $resource           = $event->resource;
        $predicates         = $event->predicates;
        $description        = $resource->getDescription();
        $this->_titleHelper = new OntoWiki_Model_TitleHelper($this->_model);

        if ($this->_privateConfig->template->restrictive) {
            foreach ($description as $resource) {
                if (isset($resource[EF_RDF_TYPE])) {
                    $type = $resource[EF_RDF_TYPE][0]['value'];
                }
            }

            if (!isset($type)) {
                return false;
            } else {
                $providedProperties = $this->_getTemplateProperties('provided', $type, false);
                $optionalProperties = $this->_getTemplateProperties('optional', $type, false);
            }
        }

        if(!empty($providedProperties)) {
            $properties = array();

            foreach ($providedProperties as $uri) {
                $properties[] = $uri['uri'];
            }

            $typedLiterals = $this->_getDatatypesForProperties($properties, false);

            $properties = array_flip($properties);
            $properties = array_fill_keys(array_keys($properties), '');

            if (!empty($typedLiterals)) {
                $properties = $this->_addInformation($properties, $typedLiterals);
            } else {
                $properties = $this->_addInformation($properties);
            }

            // write HTML5 data-* attributes for RDFauthor
            $html = '<div id=\'template-properties\' data-properties=\'';
            $html.= json_encode($properties);
            $html.= '\' ></div>' . PHP_EOL;

            $propertyRange = array();
            $providedProperties = array_merge($providedProperties, $propertyRange);

            // flatten Array and flip keys with values to use array_intersect_key
            $providedProperties = array_map(function($x) {return array_flip($x);},$providedProperties);
            // FIXME Find a method to add standard properties which will be 
            // displayed by default
            $providedProperties[] = array(EF_RDF_TYPE => '');
            $providedProperties[] = array(EF_RDFS_LABEL => '');
            $newResult = array();

            foreach ($providedProperties as $newKey => $newValue) {
                $newResult = array_merge($newResult,$newValue);
            }

            $matched = array();
            $n = 0;

            foreach($predicates as $key => $graphPredicates) {
                $intersect = array_intersect_key($predicates[$key],$newResult);
                $intersect = array((string)$key=>$intersect);
                $matched   = array_merge_recursive($intersect, $matched);
            }

            $event->predicates = $matched;
        } else {
            return false;
        }

        if($optionalProperties !== false) {
            $properties = array();

            foreach ($optionalProperties as $uri) {
                $properties[] = $uri['uri'];
            }

            $typedLiterals = $this->_getDatatypesForProperties($properties, false);

            $properties = array_flip($properties);
            $properties = array_fill_keys(array_keys($properties), '');

            if (!empty($typedLiterals)) {
                $properties = $this->_addInformation($properties, $typedLiterals);
            } else {
                $properties = $this->_addInformation($properties);
            }

            // write HTML5 data-* attributes for RDFauthor
            $html.= '<div id=\'template-optional-properties\' data-properties=\'';
            $html.= json_encode($properties);
            $html.= '\' ></div>' . PHP_EOL;
        }

        $event->templateHtml = $html;

        return true;
    }

    /**
     * This event will be triggered in rdfAuthorInitAction of ServiceController 
     * to change the behaviour for RDFauthor popover editor.
     * The result is an manipulated query result so the behaviour of the 
     * ServiceController won't be affected.
     * The method will search for existing templates with help of the rdf:type 
     * of the resource and will remove the properties that are not provided by 
     * the found templates.
     */ 
    public function onRDFAuthorInitActionTemplate($event)
    {
        $this->_model       = $event->model;
        $workingMode        = $event->mode;
        $resource           = $event->resource;
        $parameter          = $event->parameter;
        $this->_titleHelper = new OntoWiki_Model_TitleHelper($this->_model);

        $query = 'SELECT DISTINCT ?class WHERE { ' . PHP_EOL;
        $query.= '<' . $resource . '> a ?class . } ' . PHP_EOL;

        $class = $this->_model->sparqlQuery($query);

        // Class mode
        if ($workingMode == 'class') {
            if (!empty($class)) {
                if ($class['0']['uri'] == $resource) {
                    $parameter = $resource;
                }
            }

            $properties = $this->_getTemplateProperties('provided', $parameter, true);
            $optionalProperties = $this->_getTemplateProperties('optional', $parameter, true);

            if (empty($properties)) {
                return false;
            } else {
                $result = $properties['results']['bindings'];
            }

            // Add rdf:type and the class
            $arrayPos = $this->_recursiveArraySearch(EF_RDF_TYPE, $result);
            if ($arrayPos !== false) {
                $properties['results']['bindings'][$arrayPos]['value'] = array (
                    'type' => 'uri',
                    'value' => '<' . $parameter . '>');
            } else {
                $properties['results']['bindings'][] = array(
                    'uri' => array('type' => 'uri',
                                'value' => EF_RDF_TYPE),
                    'value' => array ('type' => 'uri',
                                      'value' => '<' . $parameter . '>')
                );
            }

            // Add rdfs:label with empty value
            $arrayPos = $this->_recursiveArraySearch(EF_RDFS_LABEL, $result);
            if ($arrayPos !== false) {
                $properties['results']['bindings'][$arrayPos]['value'] = array (
                    'type' => 'literal',
                    'value' => '');
            } else {
                $properties['results']['bindings'][] = array(
                    'uri' => array('type' => 'uri',
                                'value' => EF_RDFS_LABEL),
                    'value' => array ('type' => 'literal',
                                      'value' => '')
                );
            }

            $provided = array();

            foreach ($result as $property) {
                $provided[] = $property['uri']['value'];
            }

            $typedLiterals = $this->_getDatatypesForProperties($provided, false);

            $provided = array_flip($provided);
            $provided = array_fill_keys(array_keys($provided), '');

            if (!empty($typedLiterals)) {
                foreach ($typedLiterals as $typedLiteral) {
                    $arrayPos = $this->_recursiveArraySearch($typedLiteral['uri'],$result);
                    if ($arrayPos !== false) {
                        $properties['results']['bindings'][$arrayPos]
                            ['value']['type'] = 'typed-literal';
                        $properties['results']['bindings'][$arrayPos]
                         ['value']['datatype'] = $typedLiteral['typed'];

                        // Set values so rdfauthor can pick the right widgets
                        if ($typedLiteral['typed'] === EF_XSD_NS . 'date') {
                            $properties['results']['bindings'][$arrayPos]
                                ['value']['value'] = date("Y-m-d");
                        } elseif ($typedLiteral['typed'] === EF_XSD_NS . 'time') {
                            $properties['results']['bindings'][$arrayPos]
                                ['value']['value'] = date("H-i-s") . '+01:00';
                        } elseif ($typedLiteral['typed'] === EF_XSD_DATETIME) {
                            $properties['results']['bindings'][$arrayPos]
                                ['value']['value'] = date("Y-m-d") . "T" . date("H-i-s") . '+01:00';
                        } elseif ($typedLiteral['typed'] === EF_XSD_DOUBLE) {
                            $properties['results']['bindings'][$arrayPos]
                                ['value']['value'] = '0';
                        }
                    }

                    if (array_key_exists($typedLiteral['uri'], $provided)) {
                        $provided[$typedLiteral['uri']] = array(
                            'datatype' => $typedLiteral['typed']
                        );
                    }
                }
            }

            $event->addPropertyValues = $this->_addInformation($provided);

        // Edit mode
        } elseif (!empty($class) && $workingMode == 'edit') {
            $class = $class[0]['class'];
            $properties = $this->_getTemplateProperties('provided', $class, true);
            $optionalProperties = $this->_getTemplateProperties('optional', $class, true);
            $result = $properties['results']['bindings'];

            if (empty($result)) {
                return false;
            }

            foreach ($result as $property) {
                $provided[] = $property['uri']['value'];
            }

            $query = 'SELECT ?uri ?value { ' . PHP_EOL;
            $query.= '  ?template a <' . $this->_templateUri . '>. ' . PHP_EOL;
            $query.= '  ?template <' . $this->_providedPropertiesUri . '> ?uri . ' . PHP_EOL;
            $query.= '  ?template ' . $this->_bindsClassUri . ' <' . $class . '> . ' . PHP_EOL;
            $query.= '  <' . $resource . '> ?uri ?value . ' . PHP_EOL;
            $query.= '} ' . PHP_EOL;

            $properties = $this->_model->sparqlQuery($query, array('result_format' => 'extended'));
            $result = $properties['results']['bindings'];

            $provided = array_flip($provided);
            $provided = array_fill_keys(array_keys($provided), '');

            if (!empty($result)) {
                foreach ($result as $typedLiteral) {
                    if (array_key_exists($typedLiteral['uri']['value'], $provided)) {
                        $provided[$typedLiteral['uri']['value']] = array(
                            'datatype' => $typedLiteral['value']['value']
                        );
                    }
                }
            } else {
                return false;
            }
            $event->addPropertyValues = $this->_addInformation($provided);
        } else {
            return false;
        }

        // Get optional Properties

        if (!(empty($result))) {
            $result = $optionalProperties['results']['bindings'];
            $optional = array();

            foreach ($result as $property) {
                $optional[] = $property['uri']['value'];
            }

            $optional = array_flip($optional);
            $optional = array_fill_keys(array_keys($optional), '');

            $typedLiterals = $this->_getDatatypesForProperties($optional, false);

            if (!empty($typedLiterals)) {
                foreach ($typedLiterals as $typedLiteral) {
                    if (array_key_exists($typedLiteral['uri'], $optional)) {
                        $optional[$typedLiteral['uri']] = array(
                            'datatype' => $typedLiteral['typed']
                        );
                    }
                }
            }
            $event->addOptionalPropertyValues = $this->_addInformation($optional);
        }

        $event->properties = $properties;
        return true;
    }

    /**
     * Extends array_search functions and returns the key of the haystack
     * where the needle was found
     * @param $needle
     * @param $haystack
     * @return string $key
     */
    private function _recursiveArraySearch($needle, $haystack)
    {
        foreach($haystack as $key=>$value) {
                $current_key=$key;
                if($needle===$value || 
                    (is_array($value) && $this->_recursiveArraySearch($needle,$value) !== false)) {
                        return $current_key;
                }
            }
        return false;
    }

    /**
     * This method fires a SPARQL-Query to find out if a template for a given 
     * class exists and if so, it looks for the provided properties of this 
     * template and returns them.
     * @param $mode the type of template properties: 'prvodided' or 'optional'
     * @param $class the class needed to find a template
     * @param boolean $extended determines if Query should use extende results
     * @return array $result the unverified result of the SPARQL query
     */
    private function _getTemplateProperties($mode = 'provided', $class, $extended) 
    {
        if ($mode === 'optional') {
            $mode = $this->_optionalPropertiesUri;
        } elseif ($mode === 'provided') {
            $mode = $this->_providedPropertiesUri;
        } else {
            return false;
        }

        // Query for create instance (only class needed)
        $query = new Erfurt_Sparql_SimpleQuery();
        $query->setProloguePart('SELECT DISTINCT ?uri');
        $query->setWherePart( '{?template a <' . $this->_templateUri . '>.
                                ?template <' . $mode . '> ?uri .
                                ?template <' . $this->_bindsClassUri . '> <' . $class . '> .
                               } '
                );

        if ($extended === false) {
            $result = $this->_model->sparqlQuery($query);
        } else {
            $result = $this->_model->sparqlQuery($query, array('result_format'=>'extended'));
        }

        return $result;

    }

    /**
     * This method returns the result of a SPARQL query that searches typed 
     * literals of resources given in an array with help of rdfs:range and 
     * owl:DatatypeProperty
     * @param array $properties an array cotaining resources as values
     * @param boolean $extended an option if a extended SPARQL query should be 
     * used. The default is true
     * @return array $result the query result
     */
    private function _getDatatypesForProperties ($properties, $extended = true) 
    {
        $query = 'PREFIX rdfs: <'. EF_RDFS_NS . '>' . PHP_EOL;
        $query.= 'PREFIX rdf: <' . EF_RDF_NS . '>' . PHP_EOL;
        $query.= 'PREFIX owl: <' . EF_OWL_NS . '>' . PHP_EOL;
        $query.= 'SELECT DISTINCT ?uri ?typed WHERE ' . PHP_EOL;
        $query.= '{ ' . PHP_EOL;
        $query.= '  { ' . PHP_EOL;
        $query.= '    ?uri rdfs:range ?typed . ' . PHP_EOL;
        $query.= '    ?uri a          owl:DatatypeProperty . ' . PHP_EOL;
        $query.= '  } ' . PHP_EOL;
        $query.= '} ' . PHP_EOL;
        $query.= '  FILTER ( ' . PHP_EOL;
        $query.= '    ?uri = <' . implode('> || ?uri = <', $properties) . '> ) ' . PHP_EOL;
        $query.= '  )' . PHP_EOL;
        $query.= '  LIMIT 20 ' . PHP_EOL;

        if ($extended === false) {
            $result = $this->_model->sparqlQuery($query);
        } else {
            $result = $this->_model->sparqlQuery($query, array('result_format'=>'extended'));
        }

        return $result;
    }

    /**
     * This methods adds titles with help of TitleHelper and (if $typedLiterals given) 
     * the datatypes of the range of a URI
     * @param array $properties
     * @param array $typedLiterals optional an array containing information 
     * about ranges of DatatypeProperties
     * @return array $properties an enriched version of the input properties 
     * array
    */
    private function _addInformation($properties, $typedLiterals = null)
    {
        foreach ($properties as $property => $value) {
            $properties[$property]['title'] = $this->_titleHelper->getTitle($property);
        }

        if ($typedLiterals !== null) {
            foreach ($typedLiterals as $typedLiteral) {
                // Add Datatype
                if (array_key_exists($typedLiteral['uri'], $properties)) {
                    $properties[$typedLiteral['uri']]['datatype'] = $typedLiteral['typed'];
                }
            }
        }

        return $properties;
    }
}
