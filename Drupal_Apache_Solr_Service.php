<?php
// $Id$

class DrupalApacheSolrService extends Apache_Solr_Service {

  protected $server_id;
  protected $luke;
  protected $luke_cid;
  protected $stats;
  const LUKE_SERVLET = 'admin/luke';
  const STATS_SERVLET = 'admin/stats.jsp';

  /**
   * Whether {@link Apache_Solr_Response} objects should create {@link Apache_Solr_Document}s in
   * the returned parsed data
   *
   * @var boolean
   *
   * @override to FALSE by default.
   */
  protected $_createDocuments = FALSE;

  /**
   * Whether {@link Apache_Solr_Response} objects should have multivalue fields with only a single value
   * collapsed to appear as a single value would.
   *
   * @var boolean
   *
   * @override to FALSE by default
   */
  protected $_collapseSingleValueArrays = FALSE;

  /**
   * Call the /admin/ping servlet, to test the connection to the server.
   *
   * @param $timeout
   *   maximum time to wait for ping in seconds, -1 for unlimited (default 2).
   * @return
   *   (float) seconds taken to ping the server, FALSE if timeout occurs.
   */
  public function ping($timeout = 2) {
    $start = microtime(TRUE);

    if ($timeout <= 0.0) {
      $timeout = -1;
    }
    // Attempt a HEAD request to the solr ping url.
    list($data, $headers) = $this->_makeHttpRequest($this->_pingUrl, 'HEAD', array(), NULL, $timeout);
    $response = new Apache_Solr_Response($data, $headers);

    if ($response->getHttpStatus() == 200) {
      // Add 0.1 ms to the ping time so we never return 0.0.
      return microtime(TRUE) - $start + 0.0001;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Sets $this->luke with the meta-data about the index from admin/luke.
   */
  protected function setLuke($num_terms = 0) {
    if (empty($this->luke[$num_terms])) {
      $url = $this->_constructUrl(self::LUKE_SERVLET, array('numTerms' => "$num_terms", 'wt' => self::SOLR_WRITER));
      $this->luke[$num_terms] = $this->_sendRawGet($url);
      cache_set($this->luke_cid, $this->luke, 'cache_apachesolr');
    }
  }

  /**
   * Get just the field meta-data about the index.
   */
  public function getFields($num_terms = 0) {
    return $this->getLuke($num_terms)->fields;
  }

  /**
   * Get meta-data about the index.
   */
  public function getLuke($num_terms = 0) {
    if (!isset($this->luke[$num_terms])) {
      $this->setLuke($num_terms);
    }
    return $this->luke[$num_terms];
  }

  /**
   * Sets $this->stats with the information about the Solr Core form /admin/stats.jsp
   */
  protected function setStats() {
    $data = $this->getLuke();
    // Only try to get stats if we have connected to the index.
    if (empty($this->stats) && isset($data->index->numDocs)) {
      $url = $this->_constructUrl(self::STATS_SERVLET);
      $this->stats_cid = $this->server_id . ":stats:" . drupal_hash_base64($url);
      $cache = cache_get($this->stats_cid, 'cache_apachesolr');
      if (isset($cache->data)) {
        $this->stats = simplexml_load_string($cache->data);
      }
      else {
        $response = $this->_sendRawGet($url);
        $this->stats = simplexml_load_string($response->getRawResponse());
        cache_set($this->stats_cid, $response->getRawResponse(), 'cache_apachesolr');
      }
    }
  }

  /**
   * Get information about the Solr Core.
   *
   * Returns a Simple XMl document
   */
  public function getStats() {
    if (!isset($this->stats)) {
      $this->setStats();
    }
    return $this->stats;
  }

  /**
   * Get summary information about the Solr Core.
   */
  public function getStatsSummary() {
    $stats = $this->getStats();
    $summary = array(
     '@pending_docs' => '',
     '@autocommit_time_seconds' => '',
     '@autocommit_time' => '',
     '@deletes_by_id' => '',
     '@deletes_by_query' => '',
     '@deletes_total' => '',
     '@schema_version' => '',
     '@core_name' => '',
    );

    if (!empty($stats)) {
      $docs_pending_xpath = $stats->xpath('//stat[@name="docsPending"]');
      $summary['@pending_docs'] = (int) trim($docs_pending_xpath[0]);
      $max_time_xpath = $stats->xpath('//stat[@name="autocommit maxTime"]');
      $max_time = (int) trim(current($max_time_xpath));
      // Convert to seconds.
      $summary['@autocommit_time_seconds'] = $max_time / 1000;
      $summary['@autocommit_time'] = format_interval($max_time / 1000);
      $deletes_id_xpath = $stats->xpath('//stat[@name="deletesById"]');
      $summary['@deletes_by_id'] = (int) trim($deletes_id_xpath[0]);
      $deletes_query_xpath = $stats->xpath('//stat[@name="deletesByQuery"]');
      $summary['@deletes_by_query'] = (int) trim($deletes_query_xpath[0]);
      $summary['@deletes_total'] = $summary['@deletes_by_id'] + $summary['@deletes_by_query'];
      $schema = $stats->xpath('/solr/schema[1]');
      $summary['@schema_version'] = trim($schema[0]);;
      $core = $stats->xpath('/solr/core[1]');
      $summary['@core_name'] = trim($core[0]);
    }

    return $summary;
  }

  /**
   * Clear cached Solr data.
   */
  public function clearCache() {
    // Don't clear cached data if the server is unavailable.
    if (@$this->ping()) {
      $this->_clearCache();
    }
    else {
      throw new Exception('No Solr instance available when trying to clear the cache.');
    }
  }

  protected function _clearCache() {
    cache_clear_all($this->server_id . ":stats:", 'cache_apachesolr', TRUE);
    cache_clear_all($this->server_id . ":luke:", 'cache_apachesolr', TRUE);
    $this->luke = array();
    $this->stats = NULL;
  }

  /**
   * Clear the cache whenever we commit changes.
   *
   * @see Apache_Solr_Service::commit()
   */
  public function commit($optimize = TRUE, $waitFlush = TRUE, $waitSearcher = TRUE, $timeout = 3600) {
    parent::commit($optimize, $waitFlush, $waitSearcher, $timeout);
    $this->_clearCache();
  }

  /**
   * Construct the Full URLs for the three servlets we reference
   *
   * @see Apache_Solr_Service::_initUrls()
   */
  protected function _initUrls() {
    parent::_initUrls();
    $this->_lukeUrl = $this->_constructUrl(self::LUKE_SERVLET, array('numTerms' => '0', 'wt' => self::SOLR_WRITER));
  }

  /**
   * Make a request to a servlet (a path) that's not a standard path.
   *
   * @param string $servlet
   *   A path to be added to the base Solr path. e.g. 'extract/tika'
   *
   * @param array $params
   *   Any request parameters when constructing the URL.
   *
   * @param string $method
   *   'GET', 'POST', 'PUT', or 'HEAD'.
   *
   * @param array $request_headers
   *   Keyed array of header names and values.  Should include 'Content-Type'
   *   for POST or PUT.
   *
   * @param string $rawPost
   *   Must be an empty string unless method is POST or PUT.
   *
   * @param float $timeout
   *   Read timeout in seconds or FALSE.
   *
   * @return
   *  Apache_Solr_Response object
   */
  public function makeServletRequest($servlet, $params = array(), $method = 'GET', $request_headers = array(), $rawPost = '', $timeout = FALSE) {
    if ($method == 'GET' || $method == 'HEAD') {
      // Make sure we are not sending a request body.
      $rawPost = '';
    }
    // Add default params.
    $params += array(
      'wt' => self::SOLR_WRITER,
    );

    $url = $this->_constructUrl($servlet, $params);
    list($data, $headers) = $this->_makeHttpRequest($url, $method, $request_headers, $rawPost, $timeout);
    $response = new Apache_Solr_Response($data, $headers, $this->_createDocuments, $this->_collapseSingleValueArrays);
    $code = (int) $response->getHttpStatus();
    if ($code != 200) {
      $message = $response->getHttpStatusMessage();
      if ($code >= 400 && $code != 403 && $code != 404) {
        // Add details, like Solr's exception message.
        $message .= $response->getRawResponse();
      }
      throw new Exception('"' . $code . '" Status: ' . $message);
    }
    return $response;
  }

  /**
   * Put Luke meta-data from the cache into $this->luke when we instantiate.
   *
   * @see Apache_Solr_Service::__construct()
   */
  public function __construct($server_id, $host = 'localhost', $port = 8983, $path = '/solr/') {
    $this->server_id = $server_id;
    $this->setHost($host);
    $this->setPort($port);
    $this->setPath($path);

    $this->_initUrls();

    // determine our default http timeout from ini settings
    $this->_defaultTimeout = (int) ini_get('default_socket_timeout');

    // double check we didn't get 0 for a timeout
    if ($this->_defaultTimeout <= 0) {
      $this->_defaultTimeout = 60;
    }
    $this->luke_cid = $this->server_id . ":luke:" . drupal_hash_base64($this->_lukeUrl);
    $cache = cache_get($this->luke_cid, 'cache_apachesolr');
    if (isset($cache->data)) {
      $this->luke = $cache->data;
    }
  }

  function get_server_id() {
    return $this->server_id;
  }

  /**
   * Central method for making a get operation against this Solr Server
   *
   * @see Apache_Solr_Service::_sendRawGet()
   */
  protected function _sendRawGet($url, $timeout = FALSE) {
    list($data, $headers) = $this->_makeHttpRequest($url, 'GET', array(), '', $timeout);
    $response = new Apache_Solr_Response($data, $headers, $this->_createDocuments, $this->_collapseSingleValueArrays);
    $code = (int) $response->getHttpStatus();
    if ($code != 200) {
      $message = $response->getHttpStatusMessage();
      if ($code >= 400 && $code != 403 && $code != 404) {
        // Add details, like Solr's exception message.
        $message .= $response->getRawResponse();
      }
      throw new Exception('"' . $code . '" Status: ' . $message);
    }
    return $response;
  }

  /**
   * Central method for making a post operation against this Solr Server
   *
   * @see Apache_Solr_Service::_sendRawPost()
   */
  protected function _sendRawPost($url, $rawPost, $timeout = FALSE, $contentType = 'text/xml; charset=UTF-8') {
    $request_headers = array('Content-Type' => $contentType);
    list($data, $headers) = $this->_makeHttpRequest($url, 'POST', $request_headers, $rawPost, $timeout);
    $response = new Apache_Solr_Response($data, $headers, $this->_createDocuments, $this->_collapseSingleValueArrays);
    $code = (int) $response->getHttpStatus();
    if ($code != 200) {
      $message = $response->getHttpStatusMessage();
      if ($code >= 400 && $code != 403 && $code != 404) {
        // Add details, like Solr's exception message.
        $message .= $response->getRawResponse();
      }
      throw new Exception('"' . $code . '" Status: ' . $message);
    }
    return $response;
  }

  protected function _makeHttpRequest($url, $method = 'GET', $headers = array(), $content = '', $timeout = FALSE) {
    $options = array(
      'headers' => $headers,
      'method' => $method,
      'data' => $content
    );

    if ($timeout) {
      $options['timeout'] = $timeout;
    }

    $result = drupal_http_request($url, $options);

    if (!isset($result->code) || $result->code < 0) {
      $result->code = 0;
      $result->status_message = 'Request failed';
      $result->protocol = 'HTTP/1.0';
    }
    // Additional information may be in the error property.
    if (isset($result->error)) {
      $result->status_message .= ': ' . check_plain($result->error);
    }

    if (!isset($result->data)) {
      $result->data = '';
    }
    // The headers have to be reformatted for the response class.
    $headers[] = "{$result->protocol} {$result->code} {$result->status_message}";
    if (isset($result->headers)) {
      foreach ($result->headers as $name => $value) {
        $headers[] = "$name: $value";
      }
    }
    return array($result->data, $headers);
  }
}
