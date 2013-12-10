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

        $model    = $event->model;
        $graph    = $event->graph;
        $resource = $event->resource;

        $predicates = $model->getPredicates();
        $description = $resource->getDescription();

        if ($this->_privateConfig->template->restrictive) {
            foreach ($description as $resource) {
                if (isset($resource[EF_RDF_TYPE])) {
                    $type = $resource[EF_RDF_TYPE][0]['value'];
                } else {
                    return false;
                }
            }

            $query = new Erfurt_Sparql_SimpleQuery();
            $query->setProloguePart('PREFIX erm: <http://vocab.ub.uni-leipzig.de/bibrm/> SELECT DISTINCT ?uri');
            $query->addFrom((string)$event->graph);
            $query->setWherePart( '{?template a <' . $this->_template . '> .
                                    ?template erm:providesProperty ?uri .
                                    ?template erm:bindsClass <' . $type . '> .
                                } '
                    );
            $query->setLimit('20');
            $result = $store->sparqlQuery($query);
        }

        if(!empty($result)) {
            // flatten Array and flip keys with values to use array_intersect_key
            $result = array_map(function($x) {return array_flip($x);},$result);
            // FIXME Find a method to add standard properties which will be 
            // displayed by default
            $result[] = array(EF_RDF_TYPE => "bla");
            $result[] = array(EF_RDFS_LABEL => "bla");
            $newResult = array();

            foreach ($result as $newKey => $newValue) {
                $newResult = array_merge($newResult,$newValue);
            }

            $matched = array_intersect_key($predicates[(string)$graph],$newResult);
            $matched = array((string)$graph=>$matched);
            $event->predicates = $matched;
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
        $class       = $event->class;

        if ($workingMode = 'class')
        {
            $providedProperties = $model->sparqlQuery('
                PREFIX erm: <http://vocab.ub.uni-leipzig.de/bibrm/>
                SELECT ?uri WHERE {
                    ?template a <' . $this->_template . '> ;
                    erm:bindsClass <' . $resource . '> ;
                    erm:providesProperty ?uri .
                } LIMIT 20'
            );
            // Template exists
            if (!empty($providedProperties)) {
                foreach ($providedProperties as $predicate) {
                    $po = $model->sparqlQuery('
                        PREFIX erm: <http://vocab.ub.uni-leipzig.de/bibrm/>
                        SELECT ?uri ?value {
                            ?s ?uri ?value .
                            ?s a <' . $class . '> .
                            FILTER (sameTerm(?uri, <' . $predicate . '>))
                        }
                    ');
                    $properties = $array_merge($properties, $po);
                }
            } else {
                return false;
            }
        } elseif ($workingMode = 'edit') {
            $properties = $model->sparqlQuery('
                PREFIX erm: <http://vocab.ub.uni-leipzig.de/bibrm/>
                SELECT ?uri ?value {
                    ?template a <' . $this->_template . '> ;
                    erm:providesProperty ?uri ;
                    erm:bindsClass <' . $class . '> .
                    OPTIONAL {
                        <' . $resource . '> ?uri ?value .
                    } 
                } LIMIT 20 ', array('result_format' => 'extended')
            );
        }

        // if a template suits the class (reosurceuri) add rdf:type
        if (!empty($properties['results']['bindings'])) {
            $properties['results']['bindings'] =
                array_merge(array(array('uri' => array(
                                'value' => "http://www.w3.org/1999/02/22-rdf-syntax-ns#type",
                                'type'  => 'uri'))),
                            $properties['results']['bindings']);
        } else {
            if ($workingMode == 'class') {
                $properties = $this->_getPropertiesFromVocab($providedProperties);
            } else {
                return false;
            }
        }

        $event->properties = $properties;
        return true;
    }

    /**
     * This method can be called if there exists a template that suits a class 
     * but there are no instances of this class. It will search these 
     * properties in the store and set values according to the rdfs:range of 
     * the found properties
     * @param array $providedProperties the return value of the SPARQL query to 
     * find provided properties of a template
     * @return array $properties the newly created array containing the 
     * properties with correct typed literal values or without if no vocabulary 
     * was found
     */
    private function _getPropertiesFromVocab(array $providedProperties)
    {
        $properties = array();
        foreach ($providedProperties as $property) {
            $query = $model->sparqlQuery('
                    PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
                    SELECT DISTINCT ?type WHERE {
                        ?s <' . $property['value'] . '> rdfs:range .
                        ?s <' . $property['value'] . '> ?type .
                    }
                ');
        }
    }
}
