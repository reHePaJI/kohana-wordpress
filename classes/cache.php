<?php

global $hyper_cache_stop;

$hyper_cache_stop = FALSE;
$hyper_found_tag = FALSE;

// If no-cache header support is enabled and the browser explicitly requests a fresh page, do not cache
if ($hyper_cache_nocache &&
((!empty($_SERVER['HTTP_CACHE_CONTROL']) && $_SERVER['HTTP_CACHE_CONTROL'] == 'no-cache') ||
(!empty($_SERVER['HTTP_PRAGMA']) && $_SERVER['HTTP_PRAGMA'] == 'no-cache'))) return hyper_cache_exit();

// Do not cache post request (comments, plugins and so on)
if ($_SERVER["REQUEST_METHOD"] == 'POST') return hyper_cache_exit();

// Try to avoid enabling the cache if sessions are managed with request parameters and a session is active
if (defined('SID') && SID != '') return hyper_cache_exit();

$hyper_uri = $_SERVER['REQUEST_URI'];
$hyper_qs = strpos($hyper_uri, '?');

if ($hyper_qs !== false) {
  if ($hyper_cache_strip_qs) $hyper_uri = substr($hyper_uri, 0, $hyper_qs);
  else if (!$hyper_cache_cache_qs) return hyper_cache_exit();
}

if (strpos($hyper_uri, 'robots.txt') !== false) return hyper_cache_exit();

// Checks for rejected url
if ($hyper_cache_reject !== false) {
  foreach($hyper_cache_reject as $uri) {
    if (substr($uri, 0, 1) == '"') {
      if ($uri == '"' . $hyper_uri . '"') return hyper_cache_exit();
    }
    if (substr($hyper_uri, 0, strlen($uri)) == $uri) return hyper_cache_exit();
  }
}

if ($hyper_cache_reject_agents !== false) {
  $hyper_agent = strtolower($_SERVER['HTTP_USER_AGENT']);
  foreach ($hyper_cache_reject_agents as $hyper_a) {
    if (strpos($hyper_agent, $hyper_a) !== false) return hyper_cache_exit();
  }
}

// Do nested cycles in this order, usually no cookies are specified
if ($hyper_cache_reject_cookies !== false) {
  foreach ($hyper_cache_reject_cookies as $hyper_c) {
    foreach ($_COOKIE as $n=>$v) {
      if (substr($n, 0, strlen($hyper_c)) == $hyper_c) return hyper_cache_exit();
    }
  }
}

// Do not use or cache pages when a wordpress user is logged on

foreach ($_COOKIE as $n=>$v) {
  // If it's required to bypass the cache when the visitor is a commenter, stop.
  if ($hyper_cache_comment && substr($n, 0, 15) == 'comment_author_') return hyper_cache_exit();

  // SHIT!!! This test cookie makes to cache not work!!!
  if ($n == 'wordpress_test_cookie') continue;
  // wp 2.5 and wp 2.3 have different cookie prefix, skip cache if a post password cookie is present, also
  if (substr($n, 0, 14) == 'wordpressuser_' || substr($n, 0, 10) == 'wordpress_' || substr($n, 0, 12) == 'wp-postpass_') {
    return hyper_cache_exit();
  }
}

// Do not cache WP pages, even if those calls typically don't go throught this script
if (strpos($hyper_uri, '/wp-') !== false) return hyper_cache_exit();

// Multisite
if (function_exists('is_multisite') && is_multisite() && strpos($hyper_uri, '/files/') !== false) return hyper_cache_exit();

// Prefix host, and for wordpress 'pretty URLs' strip trailing slash (e.g. '/my-post/' -> 'my-site.com/my-post')
$hyper_uri = $_SERVER['HTTP_HOST'] . $hyper_uri;

// The name of the file with html and other data
$hyper_cache_name = md5($hyper_uri);
$hc_file = $hyper_cache_path . $hyper_cache_name . hyper_mobile_type() . '.dat';
$view_file = $hyper_kohana_views_dir . $hyper_cache_name . hyper_mobile_type() . $hyper_kohana_ext;
$view_template = $hyper_kohana_views_prefix . $hyper_cache_name . hyper_mobile_type();

if (!file_exists($hc_file)) {
  hyper_cache_start(false);
  return;
}

$hc_file_time = @filemtime($hc_file);
$hc_file_age = time() - $hc_file_time;

if ($hc_file_age > $hyper_cache_timeout) {
  hyper_cache_start();
  return;
}

$hc_invalidation_time = @filemtime($hyper_cache_path . '_global.dat');
if ($hc_invalidation_time && $hc_file_time < $hc_invalidation_time) {
  hyper_cache_start();
  return;
}

if (array_key_exists("HTTP_IF_MODIFIED_SINCE", $_SERVER)) {
  $if_modified_since = strtotime(preg_replace('/;.*$/', '', $_SERVER["HTTP_IF_MODIFIED_SINCE"]));
  if ($if_modified_since >= $hc_file_time) {
    header($_SERVER['SERVER_PROTOCOL'] . " 304 Not Modified");
    flush();
    die();
  }
}

// Load it and check is it's still valid
$hyper_data = @unserialize(file_get_contents($hc_file));

if (!$hyper_data) {
  hyper_cache_start();
  return;
}

if ($hyper_data['type'] == 'home' || $hyper_data['type'] == 'archive') {

  $hc_invalidation_archive_file =  @filemtime($hyper_cache_path . '_archives.dat');
  if ($hc_invalidation_archive_file && $hc_file_time < $hc_invalidation_archive_file) {
    hyper_cache_start();
    return;
  }
}

// Valid cache file check ends here

if ($hyper_data['location']) {
  header('Location: ' . $hyper_data['location']);
  flush();
  die();
}

// It's time to serve the cached page

if (!$hyper_cache_browsercache) {
  // True if browser caching NOT enabled (default)
  header('Cache-Control: no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
}
else {
  $maxage = $hyper_cache_timeout - $hc_file_age;
  header('Cache-Control: max-age=' . $maxage);
  header('Expires: ' . gmdate("D, d M Y H:i:s", time() + $maxage) . " GMT");
}

// True if user ask to NOT send Last-Modified
if (!$hyper_cache_lastmodified) {
  header('Last-Modified: ' . gmdate("D, d M Y H:i:s", $hc_file_time). " GMT");
}

header('Content-Type: ' . $hyper_data['mime']);
if ($hyper_data['status'] == 404) header($_SERVER['SERVER_PROTOCOL'] . " 404 Not Found");

// Send the cached html
if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false &&
(($hyper_cache_gzip && !empty($hyper_data['gz'])) || ($hyper_cache_gzip_on_the_fly && function_exists('gzencode')))) {
  header('Content-Encoding: gzip');
  header('Vary: Accept-Encoding');
  if (!empty($hyper_data['gz'])) {
    echo $hyper_data['gz'];
  }
  else {
    echo gzencode( $hyper_data['html']);
  }
}
else {
  // No compression accepted, check if we have the plain html or
  // decompress the compressed one.
  if ($hyper_data['html']) {
    //header('Content-Length: ' . strlen($hyper_data['html']));
    echo $hyper_data['html'];
  }
  else if (function_exists('gzinflate')) {
    $buffer = hyper_cache_gzdecode($hyper_data['gz']);
    if ($buffer === false) echo 'Error retrieving the content';
    else echo $buffer;
  }
  else {
    // Cannot decode compressed data, serve fresh page
    return false;
  }
}
flush();
die();

function hyper_cache_start($delete=true) {
  global $hc_file;

  if ($delete) @unlink($hc_file);
  foreach ($_COOKIE as $n=>$v ) {
    if (substr($n, 0, 14) == 'comment_author') {
      unset($_COOKIE[$n]);
    }
  }
  ob_start('hyper_cache_callback');
  require_once WP_CONTENT_DIR . '/plugins/kadapter/kohana_index.php';
  require_once WP_CONTENT_DIR . '/plugins/kadapter/kohana_bootstrap.php';
}

// From here Wordpress starts to process the request

// Called whenever the page generation is ended
function hyper_cache_callback($buffer) {
  global $hyper_cache_notfound, $hyper_cache_stop, $hyper_cache_charset, $hyper_cache_home,
    $hyper_cache_redirects, $hyper_redirect, $hc_file, $hyper_cache_name,
    $hyper_cache_browsercache, $hyper_cache_timeout, $hyper_cache_lastmodified, $hyper_cache_gzip,
    $hyper_cache_gzip_on_the_fly, $hyper_cache_store_compressed, $hyper_cache_feed;

  if (!function_exists('is_home')) return $buffer;

  if (function_exists('apply_filters')) $buffer = apply_filters('hyper_cache_buffer', $buffer);

  if ($hyper_cache_stop) return $buffer;

  if (!$hyper_cache_notfound && is_404()) {
    return $buffer;
  }

  if (strpos($buffer, '</body>') === false) return $buffer;

  // WP is sending a redirect
  if ($hyper_redirect) {
    if ($hyper_cache_redirects) {
      $data['location'] = $hyper_redirect;
      WordpressCache::getInstance()->content = $data;
    }
    return $buffer;
  }

  if (is_home() && $hyper_cache_home) {
    return $buffer;
  }

  if (is_feed() && !$hyper_cache_feed) {
    return $buffer;
  }

  if (is_home()) $data['type'] = 'home';
  else if (is_feed()) $data['type'] = 'feed';
  else if (is_archive()) $data['type'] = 'archive';
  else if (is_single()) $data['type'] = 'single';
  else if (is_page()) $data['type'] = 'page';
  $buffer = trim($buffer);

  // Can be a trackback or other things without a body. We do not cache them, WP needs to get those calls.
  if (strlen($buffer) == 0) return '';

  if (!$hyper_cache_charset) $hyper_cache_charset = 'UTF-8';

  if (is_feed()) {
    $data['mime'] = 'text/xml;charset=' . $hyper_cache_charset;
  }
  else {
    $data['mime'] = 'text/html;charset=' . $hyper_cache_charset;
  }

  $buffer .= '<!-- hyper cache: ' . $hyper_cache_name . ' ' . date('y-m-d h:i:s') .' -->';

  $data['html'] = $buffer;

  if (is_404()) $data['status'] = 404;

  WordpressCache::getInstance()->content = $data;

  if ($hyper_cache_browsercache) {
    header('Cache-Control: max-age=' . $hyper_cache_timeout);
    header('Expires: ' . gmdate("D, d M Y H:i:s", time() + $hyper_cache_timeout) . " GMT");
  }

  // True if user ask to NOT send Last-Modified
  if (!$hyper_cache_lastmodified) {
    header('Last-Modified: ' . gmdate("D, d M Y H:i:s", @filemtime($hc_file)). " GMT");
  }

  return '';
}

function hyper_mobile_type() {
  global $hyper_cache_mobile, $hyper_cache_mobile_agents, $hyper_cache_plugin_mobile_pack;

  if ($hyper_cache_plugin_mobile_pack) {
    @include_once ABSPATH . 'wp-content/plugins/wordpress-mobile-pack/plugins/wpmp_switcher/lite_detection.php';
    if (function_exists('lite_detection')) {
      $is_mobile = lite_detection();
      if (!$is_mobile) return '';
      include_once ABSPATH . 'wp-content/plugins/wordpress-mobile-pack/themes/mobile_pack_base/group_detection.php';
      if (function_exists('group_detection')) {
        return 'mobile' . group_detection();
      }
      else return 'mobile';
    }
  }

  if (!isset($hyper_cache_mobile) || $hyper_cache_mobile_agents === false) return '';

  $hyper_agent = strtolower($_SERVER['HTTP_USER_AGENT']);
  foreach ($hyper_cache_mobile_agents as $hyper_a) {
    if (strpos($hyper_agent, $hyper_a) !== false) {
      if (strpos($hyper_agent, 'iphone') || strpos($hyper_agent, 'ipod')) {
        return 'iphone';
      }
      else {
        return 'pda';
      }
    }
  }
  return '';
}

function hyper_cache_gzdecode ($data) {

  $flags = ord(substr($data, 3, 1));
  $headerlen = 10;
  $extralen = 0;

  $filenamelen = 0;
  if ($flags & 4) {
    $extralen = unpack('v' ,substr($data, 10, 2));

    $extralen = $extralen[1];
    $headerlen += 2 + $extralen;
  }
  if ($flags & 8) // Filename

  $headerlen = strpos($data, chr(0), $headerlen) + 1;
  if ($flags & 16) // Comment

  $headerlen = strpos($data, chr(0), $headerlen) + 1;
  if ($flags & 2) // CRC at end of file

  $headerlen += 2;
  $unpacked = gzinflate(substr($data, $headerlen));
  return $unpacked;
}

function hyper_cache_exit() {
  global $hyper_cache_gzip_on_the_fly;

  if ($hyper_cache_gzip_on_the_fly && extension_loaded('zlib')) ob_start('ob_gzhandler');

  require_once WP_CONTENT_DIR . '/plugins/kadapter/kohana_index.php';
  require_once WP_CONTENT_DIR . '/plugins/kadapter/kohana_bootstrap.php';
  return false;
}

/**
 * wp_cache_add() - Adds data to the cache, if the cache key doesn't aleady exist
 *
 * @param int|string $key The cache ID to use for retrieval later
 * @param mixed $data The data to add to the cache store
 * @param string $flag The group to add the cache to
 * @param int $expire When the cache data should be expired
 * @return unknown
 */
function wp_cache_add($key, $data, $flag = '', $expire = 0)
{
  global $wp_object_cache;
  if (empty($flag)) { $flag = 'default'; }
  return $wp_object_cache->add($key, $data, $flag, $expire);
}

/**
 * wp_cache_close() - Closes the cache
 *
 * @return bool Always returns True
 */
function wp_cache_close()
{
  global $wp_object_cache;
  $wp_object_cache->close();
  return true;
}

/**
 * wp_cache_delete() - Removes the cache contents matching ID and flag
 *
 * @param int|string $id What the contents in the cache are called
 * @param string $flag Where the cache contents are grouped
 * @return bool True on successful removal, false on failure
 */
function wp_cache_delete($id, $flag = '')
{
  global $wp_object_cache;
  if (empty($flag)) { $flag = 'default'; }
  return $wp_object_cache->delete($id, $flag);
}

/**
 * wp_cache_flush() - Removes all cache items
 *
 * @return bool Always returns true
 */
function wp_cache_flush()
{
  global $wp_object_cache;
  $wp_object_cache->flush();
  return true;
}

/**
 * wp_cache_get() - Retrieves the cache contents from the cache by ID and flag
 *
 * @param int|string $id What the contents in the cache are called
 * @param string $flag Where the cache contents are grouped
 * @return bool|mixed False on failure to retrieve contents or the cache contents on success
 */
function wp_cache_get($id, $flag = '')
{
  global $wp_object_cache;
  if (empty($flag)) { $flag = 'default'; }
  return $wp_object_cache->get($id, $flag);
}

function wp_cache_init()
{
  static $initialized = false;
  if ($initialized) {
    wp_cache_reset();
    return;
  }

  $initialized = true;

  $options = array();

  if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = null;
    $options['persist'] = false;
  }

  $GLOBALS['wp_object_cache'] = LazyCache::instance('object-file',$options);
}

/**
 * Reset internal cache keys and structures. If the cache backend uses global blog or site IDs as part of its cache keys,
 * this function instructs the backend to reset those keys and perform any cleanup since blog or site IDs have changed since cache init.
 */
function wp_cache_reset()
{
  global $wp_object_cache;
  if (is_object($wp_object_cache))
    $wp_object_cache->reset();
  else
    wp_cache_init();
}

/**
 * wp_cache_replace() - Replaces the contents of the cache with new data
 *
 * @param int|string $id What to call the contents in the cache
 * @param mixed $data The contents to store in the cache
 * @param string $flag Where to group the cache contents
 * @param int $expire When to expire the cache contents
 * @return bool False if cache ID and group already exists, true on success
 */
function wp_cache_replace($key, $data, $flag = '', $expire = 0)
{
  global $wp_object_cache;
  if (empty($flag)) { $flag = 'default'; }
  return $wp_object_cache->replace($key, $data, $flag, $expire);
}

/**
 * wp_cache_set() - Saves the data to the cache
 *
 * @param int|string $id What to call the contents in the cache
 * @param mixed $data The contents to store in the cache
 * @param string $flag Where to group the cache contents
 * @param int $expire When to expire the cache contents
 * @return bool False if cache ID and group already exists, true on success
 */
function wp_cache_set($key, $data, $flag = '', $expire = 0)
{
  global $wp_object_cache;
  if (empty($flag)) { $flag = 'default'; }
  return $wp_object_cache->set($key, $data, $flag, $expire);
}

/**
 * Adds a group or set of groups to the list of global groups.
 *
 * @param string|array $groups A group or an array of groups to add
 */
function wp_cache_add_global_groups($groups)
{
  global $wp_object_cache;
  if (!is_array($groups)) {
    $groups = array($groups);
  }

  $wp_object_cache->addGlobalGroups($groups);
}

/**
 * Adds a group or set of groups to the list of non-persistent groups.
 *
 * @param string|array $groups A group or an array of groups to add
 */
function wp_cache_add_non_persistent_groups($groups)
{
  global $wp_object_cache;
  if (!is_array($groups)) {
    $groups = array($groups);
  }

  $wp_object_cache->addNonPersistentGroups($groups);
}
