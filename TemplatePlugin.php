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
    private $_template = 'http://vocab.ub.uni-leipzig.de/bibrm/Template';

    public function onPropertiesActionTemplate($event)
    {
        $store  = Erfurt_App::getInstance()->getStore();
        $config = Erfurt_App::getInstance()->getConfig();

        $model         = $event->model;
        $selectedModel = $event->selectedModel;
        $graph         = $event->graph;
        $resource      = $event->resource;
        $predicates    = $model->getPredicates();
        $description   = $resource->getDescription();

        if (!$this->_privateConfig->template->restrictive) {
            foreach ($description as $resource) {
                if (isset($resource[EF_RDF_TYPE])) {
                    $type = $resource[EF_RDF_TYPE][0]['value'];
                } else {
                    return false;
                }
            }

            $result = $this->_getProvidedProperties($selectedModel, $type, false);
        }

        if(!empty($result)) {
            $properties = array();
            foreach($result as $uri) {
                $properties[] = $uri['uri'];
            }

            $typedLiterals = $this->_getDatatypesForProperties($selectedModel, $properties, false);

            // add rdfs:range of properties with type owl:DatatypeProperty
            $properties = array_flip($properties);
            $properties = array_fill_keys(array_keys($properties), '');

            if (!empty($typedLiterals)) {
                $propertyRange = array();
                foreach ($typedLiterals as $typedLiteral) {
                    if (array_key_exists($typedLiteral['uri'], $properties)) {
                        $properties[$typedLiteral['uri']] = array(
                            'datatype' => $typedLiteral['typed'],
                        );
                    }
                }
                $result = array_merge($result, $propertyRange);
            }
            // write HTML5 data-* attributes for RDFauthor
            $html = '<div id=\'template-properties\' data-properties=\'';
            $html.= json_encode($properties);
            $html.= '\' ></div>' . PHP_EOL;

            // flatten Array and flip keys with values to use array_intersect_key
            $result = array_map(function($x) {return array_flip($x);},$result);
            // FIXME Find a method to add standard properties which will be 
            // displayed by default
            $result[] = array(EF_RDF_TYPE => '');
            $result[] = array(EF_RDFS_LABEL => '');
            $newResult = array();

            foreach ($result as $newKey => $newValue) {
                $newResult = array_merge($newResult,$newValue);
            }

            $matched = array_intersect_key($predicates[(string)$graph],$newResult);
            $matched = array((string)$graph=>$matched);
            $event->predicates = $matched;
            $event->templateHtml = $html;
        } else {
            return false;
        }
        return true;
    }

    public function onRDFAuthorInitActionTemplate($event)
    {
        $model       = $event->model;
        $workingMode = $event->mode;
        $resource    = $event->resource;
        $parameter   = $event->parameter;

        $query = 'SELECT DISTINCT ?class WHERE { ' . PHP_EOL;
        $query.= '<' . $resource . '> a ?class . } ' . PHP_EOL;

        $class = $model->sparqlQuery($query);

        // Class mode
        if ($workingMode == 'class')
        { 
            if (!empty($class)) {
                if ($class['0']['uri'] == $resource) {
                    $parameter = $resource;
                }
            }

            $query = 'PREFIX erm: <http://vocab.ub.uni-leipzig.de/bibrm/> ' . PHP_EOL;
            $query.= 'SELECT DISTINCT ?uri WHERE { ' . PHP_EOL;
            $query.= '?template a <' . $this->_template . '> . ' . PHP_EOL;
            $query.= '?template erm:bindsClass <' . $parameter . '> . ' . PHP_EOL;
            $query.= 'OPTIONAL { ?template erm:providesProperty ?uri . }} ' . PHP_EOL;

            $properties = $model->sparqlQuery($query, array('result_format' => 'extended'));
            $properties = $this->_getProvidedProperties($model, $parameter, true);
            $result = $properties['results']['bindings'];

            if (empty($result)) {
                return false;
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
                    'type' => 'uri',
                    'value' => '');
            } else {
                $properties['results']['bindings'][] = array(
                    'uri' => array('type' => 'uri',
                                'value' => EF_RDFS_LABEL),
                    'value' => array ('type' => 'uri',
                                      'value' => '')
                );
            }

            $provided = array();

            foreach ($result as $property) {
                $provided[] = $property['uri']['value'];
            }

            $addPropertyValues = array_flip($provided);
            $addPropertyValues = array_fill_keys(array_keys($addPropertyValues), '');

            $typedLiterals = $this->_getDatatypesForProperties($model, $provided, false);

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
                        }
                    }

                    if (array_key_exists($typedLiteral['uri'], $addPropertyValues)) {
                        $addPropertyValues[$typedLiteral['uri']] = array(
                            'datatype' => $typedLiteral['typed'],
                        );
                    }
                }
            }

            $event->addPropertyValues = $addPropertyValues;

        // Edit mode
        } elseif (!empty($class) && $workingMode == 'edit') {
            $class = $class[0]['class'];

            $properties = $this->_getProvidedProperties($model, $class, true);
            $result = $properties['results']['bindings'];

            if (empty($result)) {
                return false;
            }

            foreach ($result as $property) {
                $provided[] = $property['uri']['value'];
            }

            $query = 'PREFIX erm: <http://vocab.ub.uni-leipzig.de/bibrm/> ' . PHP_EOL;
            $query.= 'SELECT ?uri ?value { ' . PHP_EOL;
            $query.= '  ?template a <' . $this->_template . '> . ' . PHP_EOL;
            $query.= '  ?template erm:providesProperty ?uri . ' . PHP_EOL;
            $query.= '  ?template erm:bindsClass <' . $class . '> . ' . PHP_EOL;
            $query.= '  <' . $resource . '> ?uri ?value . ' . PHP_EOL;
            $query.= '} LIMIT 20 ' . PHP_EOL;

            $properties = $model->sparqlQuery($query, array('result_format' => 'extended'));
            $result = $properties['results']['bindings'];

            $provided = array_flip($provided);
            $provided = array_fill_keys(array_keys($provided), '');

            if (!empty($result)) {
                foreach ($result as $typedLiteral) {
                    if (array_key_exists($typedLiteral['uri']['value'], $provided)) {
                        $provided[$typedLiteral['uri']['value']] = array(
                            'datatype' => $typedLiteral['value']['value'],
                        );
                    }
                }
            } else {
                return false;
            }
            $event->addPropertyValues = $provided;
        } else {
            return false;
        }

        $event->properties = $properties;
        return true;
    }

    /**
     * Extends array_search functions and returns the key of the haystack
     * where the needle was found
     * @param $needle
     * @param $haystack
     * @return string $key of the haystack
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
     * @param $model the model the event object got
     * @param $class the class needed to find a template
     * @param boolean $extended determines if Query should use extende results
     * @return array $result the unverified result of the SPARQL query
     */
    private function _getProvidedProperties($model, $class, $extended) 
    {
        // Query for create instance (only class needed)
        $query = new Erfurt_Sparql_SimpleQuery();
        $query->setProloguePart('PREFIX erm: <http://vocab.ub.uni-leipzig.de/bibrm/> SELECT DISTINCT ?uri');
        $query->setWherePart( '{?template a <' . $this->_template . '> .
                                ?template erm:providesProperty ?uri .
                                ?template erm:bindsClass <' . $class . '> .
                            } '
                );

        if ($extended === false) {
            $result = $model->sparqlQuery($query);
        } else {
            $result = $model->sparqlQuery($query, array('result_format'=>'extended'));
        }

        return $result;

    }

    private function _getDatatypesForProperties ($model, $properties, $extended) 
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
            $result = $model->sparqlQuery($query);
        } else {
            $result = $model->sparqlQuery($query, array('result_format'=>'extended'));
        }

        return $result;
    }
}
