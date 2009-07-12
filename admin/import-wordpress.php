<?
ini_set('upload_max_filesize', '200M');
ini_set('post_max_size', '200M');
require_once '_base.php';

# todo: auth

/*class GBPost {
	# GBContent:
	public $name; # relative to root tree
	public $id;
	public $mimeType = null;
	public $author = null;
	public $modified = false; # GBDateTime
	public $published = false; # GBDateTime
	
	# GBExposedContent:
	public $slug;
	public $meta;
	public $title;
	public $body;
	public $tags = array();
	public $categories = array();
	public $comments;
	public $commentsOpen = true;
	public $pingbackOpen = true;
	public $draft = false;
	
	# GBPost:
	public $excerpt;
}*/

class WPPost extends GBPost {
	public $wpid = 0;
	public $wpparent;
}
class WPPage extends GBPage {
	public $wpid = 0;
	public $wpparent;
}
class WPAttachment extends GBContent {
	public $wpid = 0;
	public $wpparent;
	public $wpfilename;
	public $wpurl;
	public $wpmeta = array();
}
class WPComment extends GBComment {
	/*
	$date;
	$ipAddress;
	$email;
	$uri;
	$name;
	$body;
	$approved;
	$comments;
	*/
	public $wpid = 0; # used for sorting
	public $wpdateutc = 0; # used for sorting
}

class WordpressImporter {
	public $doc;
	public $objectCount;
	protected $origErrhandler;
	protected $origErrhtml;
	
	function __construct() {
		$this->importedObjectsCount = 0;
	}
	
	function writemsg($timestr, $msgstr, $cssclass='') {
		echo '<div class="msg '.$cssclass.'">'
				. '<p class="time">'.$timestr.'</p>'
				. '<p class="msg">'.$msgstr.'</p>'
				. '<div class="breaker"></div>'
			. '</div>'
			. '<script type="text/javascript" charset="utf-8">setTimeout(\'window.scrollBy(0,999999);\',50)</script>';
		flush();
	}
	
	function report($msg) {
		$vargs = func_get_args();
		if(count($vargs) > 1) {
			$fmt = array_shift($vargs);
			$msg .= vsprintf($fmt, $vargs);
		}
		return $this->writemsg(date('H:i:s').substr(microtime(), 1, 4), $msg);
	}

	function reportError($msg) {
		$vargs = func_get_args();
		if(count($vargs) > 1) {
			$fmt = array_shift($vargs);
			$msg .= vsprintf($fmt, $vargs);
		}
		return $this->writemsg(date('H:i:s').substr(microtime(), 1, 4), $msg, 'error');
	}
	
	function dump($obj, $name=null) {
		return;
		return $this->writemsg(h($name !== null ? $name : gettype($obj)), '<pre>'.h(var_export($obj,1)).'</pre>');
	}
	
	function import(DOMDocument $doc, $commitChannels=true) {
		$this->doc = $doc;
		$count = 0;
		$this->beginCatchPHPErrors();
		$exception = null;
		$timer = microtime(1);
		
		try {
			foreach ($doc->getElementsByTagName('channel') as $channel) {
				if ($channel->nodeType !== XML_ELEMENT_NODE)
					continue;
				$this->importChannel($channel, $commitChannels);
				$count++;
			}
		}
		catch (Exception $e) {
			$exception = $e;
		}
		
		$this->endCatchPHPErrors();
		if ($exception !== null)
			throw $exception;
		
		$timer = microtime(1)-$timer;
		
		$this->report('Imported '.counted($count, 'channel', 'channels', 'zero', 'one')
			. ' in '.$this->fmttimelength($timer));
	}
	
	function importChannel(DOMNode $channel, $commit) {
		$channel_name = $channel->getElementsByTagName('title')->item(0)->nodeValue;
		$this->report('Importing channel "'.h($channel_name).'"');
		$fallbackTZOffset = $this->deduceChannelTimezoneOffset($channel);
		$count_posts = 0;
		$count_pages = 0;
		$count_attachments = 0;
		$timer = microtime(1);
		
		GitBlog::reset(); # rollback any previously prepared commit
		
		try {
			foreach ($channel->getElementsByTagName('item') as $item) {
				if ($item->nodeType !== XML_ELEMENT_NODE)
					continue;
				$obj = $this->importItem($item, $fallbackTZOffset);
				if (!$obj)
					continue;
				if ($obj instanceof GBExposedContent) {
					$this->postProcessExposedContent($obj);
					$this->report('Importing '.($obj instanceof WPPost ? 'post' : 'page').' '.h($obj->name)
						.' "'.h($obj->title).'" by '.h($obj->author->name).' published '.$obj->published);
					if ($this->writeExposedContent($obj)) {
						if ($obj instanceof WPPost)
							$count_posts++;
						else
							$count_pages++;
					}
				}
				elseif ($obj instanceof WPAttachment) {
					$this->postProcessAttachment($obj);
					$this->report('Importing attachment '.h($obj->name).' ('.h($obj->wpurl).')');
					if ($this->writeAttachment($obj))
						$count_attachments++;
				}
				$this->dump($obj);
			}
		
			$timer = microtime(1)-$timer;
			$count = $count_posts+$count_pages+$count_attachments;
			$this->importedObjectsCount += $count;
			
			$message = 'Imported '
				. counted($count, 'object', 'objects', 'zero', 'one')
				. ' ('
				. counted($count_posts, 'post', 'posts', 'zero', 'one')
				. ', '
				. counted($count_pages, 'page', 'pages', 'zero', 'one')
				. ' and '
				. counted($count_attachments, 'attachment', 'attachments', 'zero', 'one')
				. ')';
		
			$this->report($message.' from channel "'.h($channel_name).'"'
				. ' in '.$this->fmttimelength($timer));
			
			if ($commit) {
				$this->report('Creating commit...');
				try {
					GitBlog::commit($message.' from Wordpress blog '.$channel_name,
						GBUserAccount::getAdmin()->gitAuthor());
					$this->report('Committed to git repository');
				}
				catch (GitError $e) {
					if (strpos($e->getMessage(), 'nothing added to commit') !== false)
						$this->report('Nothing committed because no changes where done');
					else
						throw $e;
				}
			}
		}
		catch (Exception $e) {
			GitBlog::reset(); # rollback prepared commit
			throw $e;
		}
	}
	
	function fmttimelength($f) {
		$i = intval($f);
		return gmstrftime('%H:%M:%S.', $i).sprintf('%03d', round($f*1000.0)-($i*1000));
	}
	
	function postProcessExposedContent(GBExposedContent $obj) {
		# Draft objects which have never been published does not have a slug, so
		# we derive one from the title:
		if (!$obj->slug)
			$obj->slug = GBFilter::apply('sanitize-title', $obj->title);
		else
			$obj->slug = preg_replace('/\/+/', '-', urldecode($obj->slug));
		# pathspec
		if ($obj instanceof WPPost)
			$obj->name = 'content/posts/'.$obj->published->utcformat('%Y/%m/%d-').$obj->slug.'.html';
		else
			$obj->name = 'content/pages/'.$obj->slug.'.html';
	}
	
	function postProcessAttachment(WPAttachment $obj) {
		# pathspec
		$obj->name = 'attachments/'.$obj->published->utcformat('%Y/%m/').basename($obj->wpfilename);
	}
	
	function mkdirs($path, $maxdepth, $mode=0775) {
		if ($maxdepth <= 0)
			return;
		$parent = dirname($path);
		if (!is_dir($parent))
			$this->mkdirs($parent, $maxdepth-1, $mode);
		mkdir($path, $mode);
		@chmod($path, $mode);
	}
	
	function writeExposedContent(GBExposedContent $obj) {
		$dstpath = GB_SITE_DIR.'/'.$obj->name;
		$this->dump($dstpath);
		
		# assure destination dir is prepared
		$dstpathdir = dirname($dstpath);
		if (!is_dir($dstpathdir))
			$this->mkdirs($dstpathdir, count(explode('/', trim($obj->name, '/'))));
		
		# build meta
		$meta = array_merge($obj->meta, array(
			'title' => $obj->title,
			'published' => $obj->published->__toString(),
			'draft' => $obj->draft ? 'yes' : 'no',
			'comments' => $obj->commentsOpen ? 'yes' : 'no',
			'pingback' => $obj->pingbackOpen ? 'yes' : 'no',
			'wp-id' => $obj->wpid
		));
		if ($obj instanceof WPPage)
			$meta['order'] = $obj->order;
		if ($obj->tags)
			$meta['tags'] = implode(', ', $obj->tags);
		if ($obj->categories)
			$meta['categories'] = implode(', ', $obj->categories);
		
		# mux meta and body
		$data = '';
		foreach ($meta as $k => $v) {
			$k = trim($k);
			$v = trim($v);
			if (!$k || !$v)
				continue;
			$data .= $k.': '.str_replace("\n", "\n\t", $v)."\n";
		}
		if (!$data)
			$data .= "\n";
		$data .= "\n".$obj->body."\n";
		
		# write
		gb_atomic_write($dstpath, $data, 0664);
		
		# add to commit cache
		GitBlog::add($obj->name);
		
		return true;
	}
	
	function writeAttachment(WPAttachment $obj) {
		$dstpath = GB_SITE_DIR.'/'.$obj->name;
		
		$dstpathdir = dirname($dstpath);
		if (!is_dir($dstpathdir))
			$this->mkdirs($dstpathdir, count(explode('/', trim($obj->name, '/'))));
		
		try {
			copy($obj->wpurl, $dstpath);
			@chmod($dstpath, 0664);
			return true;
		}
		catch (RuntimeException $e) {
			$this->reportError($e->getMessage());
			return false;
		}
	}
	
	function beginCatchPHPErrors() {
		$this->origErrhtml = ini_set('html_errors', '0');
		if ($this->origErrhandler === null)
			$this->origErrhandler = set_error_handler(array($this, '_catchPHPError'), E_ALL & ~E_NOTICE);
	}

	function endCatchPHPErrors() {
		if ($this->origErrhandler)
			set_error_handler($this->origErrhandler);
		ini_set('html_errors', $this->origErrhtml);
		$this->origErrhandler = null;
	}
	
	# int $errno , string $errstr [, string $errfile [, int $errline [, array $errcontext ]]]
	function _catchPHPError($errno, $errstr, $errfile=null, $errline=0, $errcontext=null) {
		throw new RuntimeException($errstr, $errno);
	}
	
	function createItemObject(DOMNode $item) {
		$type = $item->getElementsByTagName('post_type')->item(0);
		# "page", "post", "attachment"
		switch ($type ? $type->nodeValue : '?') {
			case 'post': return new WPPost();
			case 'page': return new WPPage();
			case 'attachment': return new WPAttachment();
		}
		return null;
	}
	
	function importItem(DOMNode $item, $fallbackTZOffset=0) {
		$obj = $this->createItemObject($item);
		if ($obj === null) {
			$this->report('discarded unknown item <code>%s<code>', h($this->doc->saveXML($item)));
			return null;
		}
		
		$is_exposed = !($obj instanceof WPAttachment);
		$obj->mimeType = 'text/html';
		$datelocalstr = null;
		$datelocal = false;
		$dateutc = false;
		
		foreach ($item->childNodes as $n) {
			if ($n->nodeType !== XML_ELEMENT_NODE)
				continue;
			
			# we're doing doing this to avoid a bug in php where accessing this property
			# from strpos or str_replace causes a silent hang.
			$name = ''.$n->nodeName;
			
			if ($is_exposed && $name === 'title') {
				$obj->title = $n->nodeValue;
			}
			elseif ($is_exposed && $name === 'content:encoded') {
				$obj->body = $n->nodeValue;
			}
			elseif ($name === 'excerpt:encoded') {
				# will be derived from body by the content rebuilder, so in case WP
				# adds this in the future, just discard it. (In WP 2.6 this is never
				# present anyway.)
			}
			elseif ($is_exposed && $name === 'category') {
				if ( ($domain = $n->attributes->getNamedItem('domain')) !== null) {
					if ($domain->nodeValue === 'category' 
						&& $n->nodeValue !== 'Uncategorized' 
						&& $n->attributes->getNamedItem('nicename') !== 'uncategorized' 
						&& !in_array($n->nodeValue, $obj->categories))
					{
						$obj->categories[] = $n->nodeValue;
					}
					elseif ($domain->nodeValue === 'tag' && !in_array($n->nodeValue, $obj->tags)) {
						$obj->tags[] = $n->nodeValue;
					}
				}
			}
			elseif ($is_exposed && $name === 'wp:comment_status') {
				$obj->commentsOpen = ($n->nodeValue === 'open');
			}
			elseif ($is_exposed && $name === 'wp:ping_status') {
				$obj->pingbackOpen = ($n->nodeValue === 'open');
			}
			elseif ($is_exposed && $name === 'wp:post_name') {
				$obj->slug = $n->nodeValue;
			}
			elseif ($name === 'wp:post_date_gmt') {
				if ($n->nodeValue !== '0000-00-00 00:00:00')
					$dateutc = new GBDateTime($n->nodeValue);
			}
			elseif ($name === 'wp:post_date') {
				$datelocalstr = $n->nodeValue;
				$datelocal = new GBDateTime($n->nodeValue);
				$obj->wpdate = $datelocal;
			}
			elseif ($name === 'wp:menu_order' && ($obj instanceof WPPage)) {
				$obj->order = intval($n->nodeValue);
			}
			elseif ($is_exposed && $name === 'wp:status') {
				$obj->draft = ($n->nodeValue === 'draft');
			}
			elseif ($name === 'wp:post_id') {
				$obj->wpid = (int)$n->nodeValue;
			}
			elseif ($name === 'wp:postmeta') {
				if ($is_exposed === false) {
					# get attachment filename
					$k = $v = null;
					foreach ($n->childNodes as $n2) {
						if ($n2->nodeType !== XML_ELEMENT_NODE)
							continue;
						if ($n2->nodeName === 'wp:meta_key') {
							$k = $n2->nodeValue;
						}
						elseif ($n2->nodeName === 'wp:meta_value') {
							if ($k === '_wp_attached_file')
								$obj->wpfilename = $n2->nodeValue;
							elseif ($k === '_wp_attachment_metadata') {
								$obj->wpmeta = @unserialize(trim($n2->nodeValue));
								if ($obj->wpmeta === false)
									$this->wpmeta = array();
							}
						}
					}
				}
			}
			elseif ($name === 'wp:post_parent') {
				$obj->wpparent = $n->nodeValue;
			}
			elseif ($is_exposed === false && $name === 'wp:attachment_url') {
				$obj->wpurl = $n->nodeValue;
			}
			elseif ($name === 'wp:post_type') {
				# discard
			}
			elseif ($name === 'dc:creator') {
				$obj->author = (object)array(
					'name' => $n->nodeValue,
					'email' => GBUserAccount::getAdmin()->email
				);
			}
			elseif ($is_exposed && $name === 'wp:comment') {
				if ($obj->comments === null)
					$obj->comments = array();
				$obj->comments[] = $this->parseComment($n);
			}
			elseif ($is_exposed && substr($name, 0, 3) === 'wp:' && trim($n->nodeValue)) {
				$obj->meta[str_replace(array(':','_'),'-',$name)] = $n->nodeValue;
			}
		}
		
		if ($is_exposed && $obj->comments)
			$this->report('Imported '.counted(count($obj->comments), 'comment', 'comments', 'zero', 'one'));
		
		# date
		$obj->modified = $obj->published = $this->_mkdatetime($datelocal, $dateutc, $datelocalstr, $fallbackTZOffset);
		
		return $obj;
	}
	
	function _mkdatetime($local, $utc, $localstr, $fallbackTZOffset) {
		$tzoffset = $utc !== false ? $local->time - $utc->time : $fallbackTZOffset;
		if ($tzoffset !== 0)
			return new GBDateTime(str_replace(' ','T',$localstr).($tzoffset < 0 ? '-':'+').date('Hi', $tzoffset));
		else
			return $utc;
	}
	
	function parseComment(DOMNode $comment) {
		static $map = array(
			'author' => 'name',
			'author_email' => 'email',
			'author_url' => 'uri',
			'author_IP' => 'ipAddress',
			'content' => 'body'
		);
		$datelocal = 0;
		$datelocalstr = 0;
		$dateutc = 0;
		$c = new WPComment();
		foreach ($comment->childNodes as $n) {
			if ($n->nodeType !== XML_ELEMENT_NODE)
				continue;
			$name = ''.$n->nodeName;
			$k = substr($name, 11);
			if ($k === 'date_gmt') {
				$dateutc = new GBDateTime($n->nodeValue);
				$c->wpdateutc = new GBDateTime($n->nodeValue.' UTC');
			}
			elseif ($k === 'date') {
				$datelocal = new GBDateTime($n->nodeValue);
				$datelocalstr = $n->nodeValue;
			}
			elseif ($k === 'approved') {
				$c->approved = (bool)$n->nodeValue;
			}
			elseif ($k === 'id') {
				$c->wpid = (int)$n->nodeValue;
			}
			elseif ($k === 'type') {
				$c->type = ($n->nodeValue === 'pingback') ? 
					GBComment::TYPE_PINGBACK : GBComment::TYPE_COMMENT;
			}
			elseif (isset($map[$k])) {
				$dst = $map[$k];
				$c->$dst = $n->nodeValue;
			}
			else {
				gb::log(LOG_INFO, 'discarding '.$name);
			}
		}
		
		# date
		$c->date = $this->_mkdatetime($datelocal, $dateutc, $datelocalstr, 0);
		
		# fix message
		#if ($c->type === GBComment::TYPE_PINGBACK)
		#	$c->body = html_entity_decode($c->body, ENT_COMPAT, 'UTF-8');
		
		return $c;
	}
	
	function deduceChannelTimezoneOffset($channel) {
		# find timezone
		$diffs = array();
		foreach ($channel->getElementsByTagName('item') as $item) {
			if ($item->nodeType !== XML_ELEMENT_NODE)
				continue;
			$localdate = '';
			$utcdate = '';
			foreach ($item->childNodes as $n) {
				if ($n->nodeType !== XML_ELEMENT_NODE)
					continue;
				$nname = ''.$n->nodeName;
				if ($nname === 'wp:post_date')
					$localdate = $n->nodeValue;
				elseif ($nname === 'wp:post_date_gmt')
					$utcdate = $n->nodeValue;
			}
			if ($utcdate !== '0000-00-00 00:00:00') {
				# lets guess the timezone. yay
				$diff = strtotime($localdate)-strtotime($utcdate);
				if (isset($diffs[$diff]))
					$diffs[$diff]++;
				else
					$diffs[$diff] = 1;
			}
		}
		#var_export($diffs);
		if (count($diffs) === 1)
			return key($diffs);
		$k = array_keys($diffs);
		$mindiff = min($k[0],$k[1]);
		$difference = max($k[0],$k[1]) - $mindiff;
		if (count($diffs) === 2) {
			#$v = array_values($diffs);
			#echo "distribution ";var_dump(max($v[0],$v[1]) - min($v[0],$v[1]));
			#echo "difference ";var_dump($difference);
			#echo "variation ";var_dump(count($diffs));#floatval(max($k[0],$k[1])) / floatval($mindiff));
			#echo "occurance min/max ", min($v[0],$v[1]), '/', max($v[0],$v[1]), PHP_EOL;
			#echo "offsets min/max ", $mindiff, '/', max($k[0],$k[1]), PHP_EOL;
			if ($difference === $mindiff)
				return $mindiff; # most likely DST causing the variation, so 
		}
		gb::log(LOG_WARNING, 'unable to exactly deduce timezone -- guessing %s%d',
			($mindiff < 0 ? '-':'+'), $mindiff);
		return $mindiff;
	}
}

@ini_set('max_execution_time', '0');
set_time_limit(0);

gb::$title[] = 'Import Wordpress';
include '_header.php';

if (isset($_FILES['wpxml'])) {
	if ($_FILES['wpxml']['error'])
		exit(gb::log(LOG_ERR, 'file upload failed with unknown error</div></body></html>'));
	$importer = new WordpressImporter();
	?>
	<style type="text/css" media="screen">
		div.msg {
			border-top:1px solid #ddd;
		}
		div.msg.error { background:#fed; }
		div.msg p.time {
			float:left;
			font-family:monospace;
			color:#357;
			width:15%;
			margin:5px 0;
		}
		div.msg.error p.time { color:#942; }
		div.msg.error p.msg { color:#720; }
		div.msg p.msg {
			float:left;
			width:85%;
			margin:5px 0;
		}
		div.msg p.msg pre {
			font-size:80%;
		}
		p.done {
			font-size:500%;
			text-align:center;
			padding:50px 0 30px 0;
			font-family:didot,georgia;
			color:#496;
		}
		p.donelink {
			text-align:center;
			padding:10px 0 100px 0;
		}
		p.failure {
			font-size:200%;
			text-align:center;
			padding:50px 0 30px 0;
			color:#946;
		}
		p.failure pre {
			text-align:left;
			font-size:50%;
		}
	</style>
	<h2>Importing <?= h(basename($_FILES['wpxml']['name'])) ?></h2>
	<?
	
	try {
		$importer->import(DOMDocument::load($_FILES['wpxml']['tmp_name']));
		?>
		<p class="done">
			Yay! I've imported <?= counted($importer->importedObjectsCount, 'object','objects','zero','one') ?>.
		</p>
		<p class="donelink">
			<a href="<?= GB_SITE_URL ?>">Have a look at your blog &rarr;</a>
		</p>
		<?
	}
	catch (Exception $e) {
		?>
		<p class="failure">
			Import failed: <em><?= h($e->getMessage()) ?></em>
		</p>
		<p>
			<pre><?= h(strval($e)) ?></pre>
		</p>
		<?
	}
	
	?>
	<script type="text/javascript" charset="utf-8">setTimeout('window.scrollBy(0,999999);',50)</script>
	<?
}
else {
?>
<h2>Import a Wordpress blog</h2>
<form enctype="multipart/form-data" method="post" action="import-wordpress.php">
	<p>
		In your Wordpress (version &gt;=2.6) blog admin, go to tools &rarr; export and click the big button. Choose the file that was downloaded and click the "Import" button below. Yay. Let's hope this works.
	</p>
	<p>
		<input type="file" name="wpxml" />
	</p>
	<p>
		<input type="submit" value="Import" />
	</p>
</form>
<?
} # end if posted file
include '_footer.php' ?>