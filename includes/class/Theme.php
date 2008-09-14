<?php
	/**
	 * Class: Theme
	 * Various helper functions for the theming engine.
	 */
	class Theme {
		# String: $title
		# The title for the current page.
		public $title = "";

		# Variable: $twig
		# Contains the Twig_Loader instance.
		private $twig;

		/**
		 * Function: __construct
		 * Loads the Twig parser into <Theme>, and sets up the theme l10n domain.
		 */
		private function __construct() {
			$config = Config::current();

			# Load the theme translator
			if (file_exists(THEME_DIR."/locale/".$config->locale.".mo"))
				load_translator("theme", THEME_DIR."/locale/".$config->locale.".mo");

			# Load the theme's info into the Theme class.
			foreach (YAML::load(THEME_DIR."/info.yaml") as $key => $val)
				$this->$key = $val;

			$this->url = THEME_URL;

			$this->twig = new Twig_Loader(THEME_DIR,
			                              ((is_writable(INCLUDES_DIR."/caches") and !DEBUG and !PREVIEWING) ?
			                                  INCLUDES_DIR."/caches" :
			                                  null));
		}

		/**
		 * Function: pages_list
		 * Returns a simple array of list items to be used by the theme to generate a recursive array of pages.
		 */
		public function pages_list() {
			if (isset($this->pages_list))
				return $this->pages_list;

			$pages = Page::find(array("where" => "show_in_list = 1", "order" => "list_order ASC"));

			foreach ($pages as $page)
				$this->end_tags_for[$page->id] = $this->children[$page->id] = array();

			foreach ($pages as $page)
				if ($page->parent_id != 0)
					$this->children[$page->parent_id][] = $page;

			foreach ($pages as $page)
				if ($page->parent_id == 0)
					$this->recurse_pages($page);

			$array = array();

			foreach ($this->pages_flat as $page) {
				$array[$page->id] = array();
				$my_array =& $array[$page->id];

				$my_array["has_children"] = !empty($this->children[$page->id]);

				if ($my_array["has_children"])
					$this->end_tags_for[$this->get_last_linear_child($page->id)][] = array("</ul>", "</li>");

				$my_array["end_tags"] =& $this->end_tags_for[$page->id];
				$my_array["page"] = $page;
			}

			return $this->pages_list = $array;
		}

		/**
		 * Function: get_last_linear_child
		 * Helper function to <Theme.pages_list>
		 */
		public function get_last_linear_child($page, $origin = null) {
			fallback($origin, $page);

			$this->linear_children[$origin] = $page;
			foreach ($this->children[$page] as $child)
				$this->get_last_linear_child($child->id, $origin);

			return $this->linear_children[$origin];
		}

		/**
		 * Function: recurse_pages
		 * Helper function to <Theme.pages_list>
		 */
		public function recurse_pages($page) {
			$this->pages_flat[] = $page;

			foreach ($this->children[$page->id] as $child)
				$this->recurse_pages($child);
		}

		/**
		 * Function: archive_list
		 * Generates an array of all of the archives, by month.
		 *
		 * Parameters:
		 *     $limit - Amount of months to list
		 *     $order_by - What to sort it by
		 *     $order - "asc" or "desc"
		 *
		 * Returns:
		 *     $archives - The array. Each entry as "month", "year", and "url" values, stored as an array.
		 */
		public function archives_list($limit = 0, $order_by = "created_at", $order = "desc") {
			if (isset($this->archives_list["$limit,$order_by,$order"]))
				return $this->archives_list["$limit,$order_by,$order"];

			$sql = SQL::current();
			$dates = $sql->select("posts",
			                      array("DISTINCT YEAR(created_at) AS year",
			                            "MONTH(created_at) AS month",
			                            "created_at AS created_at",
			                            "COUNT(id) AS posts"),
			                      array("status = 'public'", Post::feathers()),
			                      $order_by." ".strtoupper($order),
			                      array(),
			                      ($limit == 0) ? null : $limit,
			                      null,
			                      "YEAR(created_at), MONTH(created_at)");

			$archives = array();
			while ($date = $dates->fetchObject())
				$archives[] = array("month" => $date->month,
				                    "year"  => $date->year,
				                    "when"  => $date->created_at,
				                    "url"   => url("archive/".when("Y/m/", $date->created_at)),
				                    "count" => $date->posts);

			return $this->archives_list["$limit,$order_by,$order"] = $archives;
		}

		/**
		 * Function: file_exists
		 * Returns whether the specified Twig file exists or not.
		 *
		 * Parameters:
		 *     $file - The file's name
		 */
		public function file_exists($file) {
			return file_exists(THEME_DIR."/".$file.".twig");
		}

		/**
		 * Function: stylesheets
		 * Outputs the default stylesheet links.
		 */
		public function stylesheets() {
			$visitor = Visitor::current();
			$config = Config::current();
			$trigger = Trigger::current();

			$stylesheets = array();
			Trigger::current()->filter($stylesheets, "stylesheets");

			if (!empty($stylesheets))
				$stylesheets = '<link rel="stylesheet" href="'.
				               implode('" type="text/css" media="screen" charset="utf-8" /'."\n\t\t".'<link rel="stylesheet" href="', $stylesheets).
				               '" type="text/css" media="screen" charset="utf-8" />';
			else
				$stylesheets = "";

			if (file_exists(THEME_DIR."/style.css"))
				$stylesheets = '<link rel="stylesheet" href="'.THEME_URL.'/style.css" type="text/css" media="screen" charset="utf-8" />'."\n\t\t";

			if (!file_exists(THEME_DIR."/stylesheets/") and !file_exists(THEME_DIR."/css/"))
				return $stylesheets;

			$count = 1;

			$long  = (array) glob(THEME_DIR."/stylesheets/*");
			$short = (array) glob(THEME_DIR."/css/*");

			$total = array_merge($long, $short);
			foreach($total as $file) {
				$path = preg_replace("/(.+)\/themes\/(.+)/", "/themes/\\2", $file);
				$file = basename($file);

				if (substr($file, -4) != ".css" and substr($file, -4) != ".php")
					continue;

				if ($file == "ie.css")
				    $stylesheets.= "<!--[if IE]>";
				if (preg_match("/^ie([0-9\.]+)\.css/", $file, $matches))
				    $stylesheets.= "<!--[if IE ".$matches[1]."]>";
				elseif (preg_match("/(lt|gt)ie([0-9\.]+)\.css/", $file, $matches))
				    $stylesheets.= "<!--[if ".$matches[1]." IE ".$matches[2]."]>";

				$stylesheets.= '<link rel="stylesheet" href="'.$config->chyrp_url.$path.'" type="text/css" media="'.($file == "print.css" ? "print" : "screen").'" charset="utf-8" />';

				if ($file == "ie.css" or preg_match("/(lt|gt)?ie([0-9\.]+)\.css/", $file))
					$stylesheets.= "<![endif]-->";

				$stylesheets.= "\n\t\t";

				$count++;
			}

			return $stylesheets;
		}

		/**
		 * Function: javascripts
		 * Outputs the default JavaScript script references.
		 */
		public function javascripts() {
			$route = Route::current();

			$args = "";
			foreach ($_GET as $key => $val)
				if (!empty($val) and $val != $route->action)
					$args.= "&amp;".$key."=".urlencode($val);

			$config = Config::current();
			$trigger = Trigger::current();

			$javascripts = array($config->chyrp_url."/includes/lib/gz.php?file=jquery.js",
			                     $config->chyrp_url."/includes/lib/gz.php?file=plugins.js",
			                     $config->chyrp_url.'/includes/javascript.php?action='.$route->action.$args);
			Trigger::current()->filter($javascripts, "scripts");

			$javascripts = '<script src="'.
			               implode('" type="text/javascript" charset="utf-8"></script>'."\n\t\t".'<script src="', $javascripts).
			               '" type="text/javascript" charset="utf-8"></script>';

			if (file_exists(THEME_DIR."/javascripts/") or file_exists(THEME_DIR."/js/")) {
				$long  = (array) glob(THEME_DIR."/javascripts/*.js");
				$short = (array) glob(THEME_DIR."/js/*.js");

				foreach(array_merge($long, $short) as $file)
					$javascripts.= "\n\t\t".'<script src="'.$config->chyrp_url.'/includes/lib/gz.php?file='.preg_replace("/(.+)\/themes\/(.+)/", "/themes/\\2", $file).'" type="text/javascript" charset="utf-8"></script>';

				$long  = (array) glob(THEME_DIR."/javascripts/*.js.php");
				$short = (array) glob(THEME_DIR."/js/*.js.php");
				foreach(array_merge($long, $short) as $file)
					$javascripts.= "\n\t\t".'<script src="'.$config->chyrp_url.preg_replace("/(.+)\/themes\/(.+)/", "/themes/\\2", $file).'" type="text/javascript" charset="utf-8"></script>';
			}

			return $javascripts;
		}

		/**
		 * Function: feeds
		 * Outputs the Feed references.
		 */
		public function feeds() {
			$config = Config::current();
			$request = ($config->clean_urls) ? rtrim(Route::current()->request, "/") : fix($_SERVER['REQUEST_URI']) ;
			$append = $config->clean_urls ?
			              "/feed" :
			              ((count($_GET) == 1 and Route::current()->action == "index") ?
			                   "/?feed" :
			                   "&amp;feed") ;
			$append.= $config->clean_urls ?
			              "/".urlencode($this->title) :
			              "&amp;title=".urlencode($this->title) ;

			$route = Route::current();
			$feeds = '<link rel="alternate" type="application/atom+xml" title="'.$config->name.' Feed" href="'.fallback($config->feed_url, url("feed/"), true).'" />'."\n";

			$feeds.= "\t\t".'<link rel="alternate" type="application/atom+xml" title="Current Page (if applicable)" href="'.$config->url.$request.$append.'" />';

			$feeds.= "\n\t\t";

			return $feeds;
		}

		public function load_time() {
			return timer_stop();
		}

		/**
		 * Function: load
		 * Loads a theme's file and extracts the passed array into the scope.
		 */
		public function load($file, $context = array()) {
			if (is_array($file))
				for ($i = 0; $i < count($file); $i++) {
					$check = ($file[$i][0] == "/" or preg_match("/[a-zA-Z]:\\\/", $file[$i])) ?
					             $file[$i] :
					             THEME_DIR."/".$file[$i] ;

					if (file_exists($check.".twig") or ($i + 1) == count($file))
						return $this->load($file[$i], $context);
				}

			$file = ($file[0] == "/" or preg_match("/[a-zA-Z]:\\\/", $file)) ? $file : THEME_DIR."/".$file ;
			if (!file_exists($file.".twig"))
				error(__("Template Missing"), _f("Couldn't load template: <code>%s</code>", array($file.".twig")));

			return $this->twig->getTemplate($file.".twig")->display($context);
		}

		/**
		 * Function: current
		 * Returns a singleton reference to the current class.
		 */
		public static function & current() {
			static $instance = null;
			return $instance = (empty($instance)) ? new self() : $instance ;
		}
	}
