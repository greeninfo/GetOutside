<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Setup extends CI_Controller {
/* The /setup pages are a one-time-use system for boostrapping the database, setting an admin username & password, and so on. */

public function index() {
    // should we even be here? bypass the SiteConfig model and make a direct query to the table,
    // cuz by definition we can't load a SiteConfig if there's no working database yet
    $already = TRUE;
    if (! $this->db->table_exists('config')) $already = FALSE;
    if ($already) {
        $already = $this->db->query('SELECT value FROM config WHERE keyword=?', array('title') );
        $already = $already->row();
    }
    if ($already) return redirect(site_url('administration'));

    /////
    ///// set up the blank tables
    /////

    $this->db->query("
        CREATE TABLE IF NOT EXISTS sessions (
            session_id varchar(40) DEFAULT '0' NOT NULL,
            ip_address varchar(45) DEFAULT '0' NOT NULL,
            user_agent varchar(120) NOT NULL,
            last_activity int(10) unsigned DEFAULT 0 NOT NULL,
            user_data text NOT NULL,
            PRIMARY KEY (session_id),
            KEY last_activity_idx (last_activity)
        )
    ");
    $this->db->query("
        CREATE TABLE IF NOT EXISTS users (
            id integer NOT NULL AUTO_INCREMENT,
            username varchar(50) NOT NULL,
            password varchar(40) NOT NULL,
            manager BOOLEAN NOT NULL DEFAULT FALSE,
            PRIMARY KEY (id),
            UNIQUE KEY username_idx (username)
        )
    ");
    $this->db->query("
        CREATE TABLE IF NOT EXISTS config (
            keyword varchar(50) NOT NULL,
            value text NOT NULL DEFAULT '',
            PRIMARY KEY (keyword)
        )
    ");
    $this->db->query("
        CREATE TABLE places (
            id INTEGER AUTO_INCREMENT NOT NULL,
            type varchar(50) NOT NULL,
            datasource_id integer NOT NULL,
            remoteid varchar(50),
            title varchar(50) NOT NULL DEFAULT 'Location',
            address varchar(100) NOT NULL DEFAULT '',
            description text NOT NULL DEFAULT '',
            latitude float,
            longitiude float,
            PRIMARY KEY (id),
            KEY datasource_id_idx (datasource_id)
        )
    ");
    $this->db->query("
        CREATE TABLE events (
            id INTEGER AUTO_INCREMENT NOT NULL,
            type varchar(50) NOT NULL,
            datasource_id integer NOT NULL,
            remoteid varchar(50),
            title varchar(50) NOT NULL DEFAULT 'Location',
            address varchar(100) NOT NULL DEFAULT '',
            description text NOT NULL DEFAULT '',
            latitude float,
            longitiude float,
            PRIMARY KEY (id),
            KEY datasource_id_idx (datasource_id)
        )
    ");

//GDA these are largely TBD
    $this->db->query("
        CREATE TABLE placedatasources (
            id INTEGER AUTO_INCREMENT NOT NULL,
            type varchar(50) NOT NULL,
            PRIMARY KEY (id)
        )
    ");
    $this->db->query("
        CREATE TABLE eventdatasources (
            id INTEGER AUTO_INCREMENT NOT NULL,
            type varchar(50) NOT NULL,
            PRIMARY KEY (id)
        )
    ");

    // now that we have tables, we can load models
    $this->load->model('User');
    $this->load->model('SiteConfig');
    $data = array();


    /////
    ///// default config
    /////
    $siteconfig = new SiteConfig();
    $siteconfig->set('title','Get Outside!');
    $siteconfig->set('jquitheme','pepper-grinder');
    $siteconfig->set('html_frontpage', '<h1>GetOutside!</h1>\n<p>Your new installation is up and running. To fill in this page, visit the <a href="administration">administration panel.</a></p>');
    $siteconfig->set('html_about', 'GetOutside! is a project of <a target="_blank" href="http://www.greeninfo.org/">GreenInfo Network</a> made possible by a grant for <a target="_blank" href="http://www.knightfoundation.org/">The Knight Foundation</a>.');
    $siteconfig->set('bbox_w', -179.0000);
    $siteconfig->set('bbox_s',  -89.0000);
    $siteconfig->set('bbox_e',  179.0000);
    $siteconfig->set('bbox_n',   89.0000);
    $siteconfig->set('bing_api_key', '');

    /////
    ///// initial admin password
    /////

    $admin_username = 'admin';
    for ($admin_password = '', $i = 0, $z = strlen($a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789')-1; $i != 10; $x = rand(0,$z), $admin_password .= $a{$x}, $i++);
    $user = new User();
    $user->manager  = 1;
    $user->username = $admin_username;
    $user->password = User::encryptPassword($admin_password);
    $user->save();
    $data['admin_username'] = $admin_username;
    $data['admin_password'] = $admin_password;


    /////
    ///// done!
    /////
    $this->load->view('setup/index.phtml',$data);
}


} // end of Controller