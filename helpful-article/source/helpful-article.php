<?php

/*
Plugin Name: Helpful Article
Plugin URI: https://github.com/petruchek/findco-wp-assessment
Description: OOP WordPress plugin that allows website visitors to vote on various articles.
Version: 1.0.0
Author: Val Petruchek
Author URI: https://linkedin.com/in/petruchek
Text Domain: helpful-article
Domain Path: /languages
*/

require_once(__DIR__ . "/HelpfulArticleClass.php");

$helpfulArticle = \petruchek\HelpfulArticle::getInstance();
