#!/usr/bin/env php -q
<?php

/**
 * gl2drupal.php
 * Convert Geeklog site to Drupal
 * @author filipp@mac.com
 * @version 20081019
 * @todo
 * - http://drupal.org/node/132202
 * - drupal.history?
 * Import Geeklog users, stories, comments, forums, polls and downloads
 * afp548.com modules: Forum, Search, Profile
 * 11.11.08 user privileges (authenticated, admin)
 * 17.11.08 working on threading
 * 18.12.08 forum posts and comments
 */

ini_set('display_errors', 'On');
error_reporting(E_ALL|E_STRICT);

// All arguments are mandatory
$args = array(
  'h' => 'DBhost',
  'u' => 'DBuser',
  'p' => 'DBpasswd',
  'g' => 'geeklogDB',
  'd' => 'drupalDB'
);

$opts = getopt(implode(':', array_keys($args)).':');

foreach ($args as $k => $v) {
  if (empty($opts[$k])) {
    echo "Missing argument {$v}\n";
    die("Usage: gl2drupal.php -h dbhost -u dbuser -p dbpasswd -g geeklogdb -d drupaldb\n");
  }
}

$dbpw       = $opts['p'];
$dbhost     = $opts['h'] . ":/Applications/MAMP/tmp/mysql/mysql.sock";
$dbuser     = $opts['u'];
$drupal_db  = $opts['d'];
$geeklog_db = $opts['g'];
$gl_tbl_prefix = "gl_";

$db = mysql_connect($dbhost, $dbuser, $dbpw) or exit(mysql_error() . "\n");

// Add users
mysql_query("DELETE FROM {$drupal_users}.users");
mysql_query("UPDATE {$drupal_db}.users SET `uid` = 0 WHERE `name` = ''");
$sql = <<<EOS
INSERT INTO {$drupal_db}.users
	(uid, name, pass, mail, signature, created, status)
	(SELECT uid, username, passwd, email, sig, UNIX_TIMESTAMP(regdate), 1
		FROM geeklog.{$gl_tbl_prefix}users
			WHERE {$gl_tbl_prefix}users.uid > 1)
EOS;

query($sql, "Importing users");

// Reassign user roles (Groups)
mysql_query("DELETE FROM {$drupal_db}.role WHERE `rid` > 2");

//$sql = "INSERT INTO drupal.role (name) (SELECT `grp_name` FROM geeklog.{$gl_tbl_prefix}groups)";
//query($sql, "Importing groups");

// Create "administrator" role
query("INSERT INTO {$drupal_db}.role (rid, name)
  VALUES (3, 'administrator')", "Creating admin role");

// Grant all permissions to admin role
$root_perms = "administer blocks, use PHP for block visibility, access comments, administer comments, post comments, post comments without approval, administer filters, administer menu, access content, administer content types, administer nodes, create page content, create story content, delete any page content, delete any story content, delete own page content, delete own story content, delete revisions, edit any page content, edit any story content, edit own page content, edit own story content, revert revisions, view revisions, access administration pages, access site reports, administer actions, administer files, administer site configuration, select different theme, administer taxonomy, access user profiles, administer permissions, administer users, change own username";
query("INSERT INTO {$drupal_db}.permission (rid, perm) VALUES (3, '{$root_perms}')");

/**
 * Assign Geeklog groups to corresponding Drupal roles
 * It would be possible to migrate groups verbatim, but we will focus on the  most important ones:
 * Root (has full access to the site, ID 1) => administrator (ID 3)
 * "Logged-in Users" (ID 2) => "authenticated user" (ID 2)
 */

// Migrate all registered users
$sql = <<<EOS
INSERT INTO {$drupal_db}.users_roles (uid, rid)
  (SELECT `ug_uid`, 2
    FROM {$geeklog_db}.{$gl_tbl_prefix}group_assignments
    WHERE {$gl_tbl_prefix}group_assignments.ug_main_grp_id = 2)
EOS;

query($sql, "Migrating user permissions");

// Migrate all Root users
$sql = <<<EOS
INSERT INTO {$drupal_db}.users_roles (uid, rid)
  (SELECT `ug_uid`, 3
    FROM {$geeklog_db}.{$gl_tbl_prefix}group_assignments
    WHERE {$gl_tbl_prefix}group_assignments.ug_main_grp_id = 1)
EOS;

query($sql, "Migrating admin permissions");

// Import nodes (titles)
// Reassign primary keys as auto index values (Drupal style?)
mysql_query("ALTER TABLE {$geeklog_db}.{$gl_tbl_prefix}stories DROP PRIMARY KEY");

$sql = "ALTER TABLE {$geeklog_db}.{$gl_tbl_prefix}stories ADD `idx` INT AUTO_INCREMENT PRIMARY KEY";
mysql_query($sql);

// Clear all previous content
mysql_query("DELETE FROM {$drupal_db}.node");

$sql =<<<EOS
INSERT INTO {$drupal_db}.node
	(nid, vid, type, title, uid, status, created, changed, comment, promote)
	(SELECT idx,
		idx,
		'story',
		title,
		uid,
		1,
		UNIX_TIMESTAMP(date),
		UNIX_TIMESTAMP(date),
		2,
		1
		FROM {$geeklog_db}.{$gl_tbl_prefix}stories);
EOS;

query($sql, "Importing headlines");

// Import node contents (revisions)
mysql_query("DELETE FROM {$drupal_db}.node_revisions");
$sql =<<<EOS
INSERT INTO {$drupal_db}.node_revisions
	(nid, vid, uid, title, body, teaser, timestamp, format)
	(SELECT idx,
		idx,
		uid,
		title,
		CONCAT(introtext, bodytext),
		introtext,
		UNIX_TIMESTAMP(date), 2
		FROM {$geeklog_db}.{$gl_tbl_prefix}stories)
EOS;

query($sql, "Importing stories");

/**
 * Import article comments
 * cid - comment ID
 * pid - ?
 * nid - the ID of the node (story) being commented on
 * uid - commenter's User ID
 * format - ?
 * thread - location of comment in the discussion thread.
 *    01/         -> First comment in thread
 *    02/         -> Second comment in thread
 *    02.00/      -> First comment to the second comment
 *    02.00.00/   -> Second comment to the first comment of the second comment
 */
 
// Clear all old comments
mysql_query("DELETE FROM {$drupal_db}.comments");

$sql =<<<EOS
SELECT pid,
			idx,
			{$gl_tbl_prefix}comments.uid,
			{$gl_tbl_prefix}comments.title,
			{$gl_tbl_prefix}comments.comment,
			{$gl_tbl_prefix}comments.ipaddress,
			{$gl_tbl_prefix}comments.lft,
			{$gl_tbl_prefix}comments.indent,
			UNIX_TIMESTAMP({$gl_tbl_prefix}comments.date) as date,
			0,
			1,
			username, email, {$gl_tbl_prefix}comments.sid
  FROM {$geeklog_db}.{$gl_tbl_prefix}comments, {$geeklog_db}.{$gl_tbl_prefix}stories, {$geeklog_db}.{$gl_tbl_prefix}users
  WHERE {$gl_tbl_prefix}stories.sid = {$gl_tbl_prefix}comments.sid
    AND {$gl_tbl_prefix}users.uid = {$gl_tbl_prefix}comments.uid
    AND {$gl_tbl_prefix}comments.type = 'article'
  ORDER BY {$gl_tbl_prefix}comments.sid, {$gl_tbl_prefix}comments.cid ASC
/*  ORDER BY {$gl_tbl_prefix}comments.sid, {$gl_tbl_prefix}comments.lft ASC*/
EOS;

$result = mysql_query($sql);

/**
 * Convert GL "pre-order traversal" to Drupal's "vancode"
 * http://www.sitepoint.com/article/hierarchical-data-database/2/
 * http://drupal.org/node/97327
 * Since we order by lft, we know that the next row always comes "after" the current one.
 * We only have to determine if it's a child or a sibling, which we should get from the indent.
 * 1	2	0	01/
 * 3	8	0	02/
 * 4	7	1	02.00/
 * 5	6	2	02.00.00/
 * 9	10	0	03/
 */
$sid = 0;
while ($row = mysql_fetch_array($result))
{
  // parts of this borrowed from Drupal's comment.module
/*
  // pid == 0 means comment is top level (no parent)
  if ($row['pid'] == 0) {
    $sql = "SELECT MAX(thread) FROM {$drupal_db}.comments WHERE nid = {$row['idx']}";
    $max = mysql_result(mysql_query($sql), 0);
    // Strip the "/" from the end of the thread.
    $max = rtrim($max, '/');
    // Finally, build the thread field for this new comment.
    $thread = int2vancode(vancode2int($max) + 1) .'/';
//    $sql = "SELECT MAX(thread) FROM {$drupal_db}.comments WHERE thread LIKE '%s.%%' AND nid = %d";
  } else {
    // This comment has a parent
    $sql = "SELECT `thread` FROM {$drupal_db}.comments WHERE cid = {$row['pid']}";
    $parent = mysql_result(mysql_query($sql), 0);
    
    // Get the max value in _this_ thread.
    $max = mysql_result(mysql_query("SELECT MAX(thread)
    FROM {comments} WHERE thread LIKE '{$parent}.%%' AND nid = {$row['idx']}"), 0);
    if ($max == '') {
      // First child of this parent.
      $thread = $parent.'.'. int2vancode(0) .'/';
    } else {
      // Strip the "/" at the end of the thread.
      $max = rtrim($max, '/');

      // We need to get the value at the correct depth.
      $parts = explode('.', $max);
      $parent_depth = count(explode('.', $parent));
      $last = $parts[$parent_depth];
      
      // Finally, build the thread field for this new comment.
      $thread = $parent.'.'. int2vancode(vancode2int($last) + 1) .'/';
    }
  }
*/

  // Process these as chunks of "stories"
  if ($sid != $row['sid']) {
    $sid = $row['sid'];
    $indent = (int) $row['indent'];
    if (!isset($t[$indent])) {
      $t[$indent] = 0;
    }
    $t[$indent] = $t[$indent]+1;
//    $t[$indent] = str_pad($t[$indent], 2, '0', STR_PAD_LEFT);
  } else {
    $sid = 0;
    $idx = 0;
    $indent = 0;
    $t = array();
  }
  
  print_r($t);
  // Build the thread representation
//  foreach ($)
//  foreach($t as $k => $v) {
//    $thread .= str_pad($k)
//  }
//  $thread = $index . str_repeat(".{$index}", $indent) . '/';
  $thread = implode('.', $t) . '/';
  $comment = mysql_real_escape_string($row['comment']);
  $subject = mysql_real_escape_string($row['title']);
  
  $insert = "INSERT INTO {$drupal_db}.comments
	(pid,nid,uid,subject,comment,hostname,timestamp,status,format,thread,name,mail)
	VALUES ({$row['pid']}, {$row['idx']}, {$row['uid']}, '$subject', '$comment',
	  '{$row['ipaddress']}', {$row['date']}, 0, 1, '{$thread}',
	  '{$row['username']}', '{$row['email']}')";
	query($insert, "Inserting comment thread $thread");
echo "{$thread}\n";
}
//exit();

// Remap Anonymous user
$sql = "UPDATE {$drupal_db}.comments SET `uid` = 0 WHERE `uid` = 1";
query($sql, "Remapping Anonymous user");

/**
 * Update story comment counts
 * @todo
 *  - last_comment_uid
 */
mysql_query("DELETE FROM {$drupal_db}.node_comment_statistics");
$sql =<<<EOS
INSERT INTO {$drupal_db}.node_comment_statistics
	(nid, comment_count, last_comment_timestamp, last_comment_uid)
	(SELECT idx, comments, MAX(UNIX_TIMESTAMP({$gl_tbl_prefix}comments.date)), {$gl_tbl_prefix}comments.uid
		FROM {$geeklog_db}.{$gl_tbl_prefix}comments, {$geeklog_db}.{$gl_tbl_prefix}stories
		WHERE {$gl_tbl_prefix}comments.sid = {$gl_tbl_prefix}stories.sid
		GROUP BY {$gl_tbl_prefix}stories.idx)
EOS;

query($sql, "Generating story comment counts");

// Restore forum comment coounts
// Without this forum posts don't show up at all
$sql =<<<EOS
INSERT INTO {$drupal_db}.node_comment_statistics
  (nid, comment_count, last_comment_timestamp, last_comment_uid)
  (SELECT id+9000, replies, lastupdated, uid
		FROM {$geeklog_db}.{$gl_tbl_prefix}forum_topic)
EOS;

query( $sql, "Restoring forum comment counts" );

/**
 * Import forum containers (categories)
 * Container > Forum > Post > Comment
 * "forum" = "term"
 * A Container is also a Forum (with term_hierarchy.parent_id = 0)
 * @NOTE The problem here is that forums and comments are in different
 * tables Geeklog whereas Drupal uses just term_data and node resulting in
 * ID collisions.
 * For containers, this is "solved" by adding 1000 to their IDs (as 
 * it's pretty unlikely that someone will have over 1000 
 * forums (although the auto-increment might reach that))
 */
mysql_query( "DELETE FROM {$drupal_db}.term_data" );
$sql =<<<EOS
INSERT INTO {$drupal_db}.term_data
	(tid, vid, name, description, weight)
	(SELECT id+1000, 1, cat_name, cat_dscp, cat_order
		FROM {$geeklog_db}.{$gl_tbl_prefix}forum_categories);
EOS;

query($sql, "Importing forum containers");

// Import forums
$sql =<<<EOS
INSERT INTO {$drupal_db}.term_data
	(tid, vid, name, description, weight)
	(SELECT forum_id, 1, forum_name, forum_dscp, forum_order
		FROM {$geeklog_db}.{$gl_tbl_prefix}forum_forums)
EOS;

query($sql, "Importing forums");

// The forum container flag is stored as a serialized PHP array
$containers = array();
$r = mysql_query("SELECT `id`+1000 FROM {$geeklog_db}.{$gl_tbl_prefix}forum_categories");
while ($row = mysql_fetch_row($r)) {
 $containers[] = $row[0]; 
}

// Clear previous forum containers
$sql = "DELETE FROM {$drupal_db}.variable WHERE `name` = 'forum_containers'";
mysql_query($sql);

$sql = sprintf("INSERT INTO {$drupal_db}.variable
	(name, value)
	VALUES ('forum_containers', '%s')", serialize($containers));

query($sql, "Registering forum containers");

// Update forum category hierarchy
mysql_query("DELETE FROM {$drupal_db}.term_hierarchy");

// Import categories, using straight GL ID's
// Categories are top parents, hence parent id 0
$sql =<<<EOS
INSERT INTO {$drupal_db}.term_hierarchy
	(SELECT {$gl_tbl_prefix}forum_categories.id+1000, 0
		FROM {$geeklog_db}.{$gl_tbl_prefix}forum_categories)
EOS;

query( $sql, "Setting category hierarchy" );

// Import forum hierarchy, ignoring top-level containers
$sql =<<<EOS
INSERT INTO {$drupal_db}.term_hierarchy
	(SELECT {$gl_tbl_prefix}forum_forums.forum_id, {$gl_tbl_prefix}forum_forums.forum_cat+1000
		FROM {$geeklog_db}.{$gl_tbl_prefix}forum_forums
		WHERE `forum_cat` != `forum_id`)
EOS;

query( $sql, "Setting forum hierarchy" );

/**
 * Importing forum posts. First the topics.
 * In forums, a node is the first post (the topic)
 */
 
// This will help alot later on
$sql = "ALTER TABLE {$drupal_db}.node ADD COLUMN tmp_forum_id INT";
mysql_query( $sql );

$sql =<<<EOS
INSERT INTO {$drupal_db}.node
	(nid, vid, type, title, uid, status, created, changed, comment, tmp_forum_id)
	(SELECT id+9000, id+9000, 'forum', subject, uid, 1, date,  lastupdated, 2, forum
		FROM {$geeklog_db}.{$gl_tbl_prefix}forum_topic
		WHERE {$gl_tbl_prefix}forum_topic.`pid` = 0)
EOS;

query( $sql, "Importing forum topics" );

$sql =<<<EOS
INSERT INTO {$drupal_db}.node_revisions
	(vid, nid, uid, title, body, teaser, timestamp, format)
	(SELECT id+9000, id+9000, uid, subject, comment, comment, date, 1
		FROM {$geeklog_db}.{$gl_tbl_prefix}forum_topic)
EOS;

mysql_query( $sql );

/**
 * Associate nodes (topics) with their forums
 * nid - node id
 * vid - revision id
 * tid - term (forum) id
 */
mysql_query("DELETE FROM {$drupal_db}.forum");
$sql =<<<EOS
INSERT INTO {$drupal_db}.forum
  (nid, vid, tid)
  (SELECT node.nid, node.vid, node.tmp_forum_id
    FROM {$drupal_db}.node
    WHERE node.type = 'forum')
EOS;

query($sql, "Registering forum topics");

$sql =<<<EOS
INSERT INTO {$drupal_db}.term_node
  (nid, vid, tid)
  (SELECT node.nid, node.vid, node.tmp_forum_id
    FROM {$drupal_db}.node
    WHERE node.type = 'forum')
EOS;

mysql_query( $sql );

// Import forum comments
// Ignoring first posts
$sql =<<<EOS
INSERT INTO {$drupal_db}.comments
  (pid,nid,uid,subject,comment,hostname,timestamp,status,format,thread,name)
  (SELECT 0,pid+9000,uid,subject,comment,ip,date,0,1,'01/',name
    FROM {$geeklog_db}.{$gl_tbl_prefix}forum_topic
      WHERE {$gl_tbl_prefix}forum_topic.pid > 0)
EOS;

query( $sql, "Importing forum comments" );

mysql_query( "ALTER TABLE {$drupal_db}.node DROP COLUMN tmp_forum_id" );
mysql_close( $db );

function query( $sql, $step = "Doing something" ) {
  mysql_query( $sql );
  if (!empty( $step )) {
    echo result( $step );
  }
}

function result( $action ) {
	$width = 100;
	$error = mysql_error();
	$result = ($error) ? "Fail ($error)" : "[OK]";
	$spaces = abs( $width - strlen( $action . $result ));
	$out = $action . str_repeat( " ", $spaces );
	return "$out $result\n";
}

/**
 * Flatten a modified pre-order traversal structure
 * into the Drupal form.
 * @param [string] $lft
 * @param (string) $rht
 * @param (string) $indent
 * @param (string) $current the current path
 * @return (string) a "node's" full path in Drupal notation
 */
function flatpot($lft, $rht, $indent, $current = '')
{
  $sql = "SELECT lft, rht, indent
    FROM gl_comments
    WHERE lft < $lft AND rht > $rht
    ORDER BY lft ASC";
  $result = mysql_query($sql);
  while ($row = mysql_fetch_assoc($result)) {
    $path = $current .
    $out .= flatpot($row['lft'], $row['rht'], $row['indent'], $path);
  }
  return $out;
}

/**
 * Generate vancode.
 *
 * Consists of a leading character indicating length, followed by N digits
 * with a numerical value in base 36. Vancodes can be sorted as strings
 * without messing up numerical order.
 *
 * It goes:
 * 00, 01, 02, ..., 0y, 0z,
 * 110, 111, ... , 1zy, 1zz,
 * 2100, 2101, ..., 2zzy, 2zzz,
 * 31000, 31001, ...
 */
function int2vancode($i = 0) {
  $num = base_convert((int)$i, 10, 36);
  $length = strlen($num);
  return chr($length + ord('0') - 1) . $num;
}

/**
 * Decode vancode back to an integer.
 */
function vancode2int($c = '00') {
  return base_convert(substr($c, 1), 36, 10);
}
?>