<?php
if ( !defined( 'MEDIAWIKI' ) ) {
    die( 'This is a MediaWiki extension, and must be run from within MediaWiki.' );
}

class SpecialOAuth2Client extends SpecialPage {
    public function __construct() {
        parent::__construct( 'OAuth2Client' );

        global $wgOAuth2Client;
        $this->config = $wgOAuth2Client;

        $this->request = $this->getRequest();
        $this->output = $this->getOutput();

        if(!isset($_SESSION)) session_start();

        //var_dump(wfMessage( 'MainPage' )->parse());
    }

    public function execute( $par ) {
        $this->setHeaders();

        if(!self::OAuthEnabled()){
            $this->output->showErrorPage( 'error', 'missing-parameters' );
            return;
        }

        switch($par){
            case 'callback':
                $this->_callback();
                break;

            default:
                $this->_default();
        }
    }

    private function _default(){
        global $wgUser, $wgOAuth2Client;
        global $wgServer, $wgArticlePath;

        if($wgUser->isLoggedIn() && $this->request->getText( 'force' ) != true){
            $this->output->wrapWikiMsg( "<p>$1</p>", array( 'has-login', $wgUser->mRealName ) );
            $this->output->addHTML("<form method='post'><input type='hidden' name='force' value='1'><input type='submit' value='重新登录'></form>");
        }else{
            $state = md5(rand(1000000, 9999999));
            $_SESSION['OAuthState'] = $state;
            $_SESSION['returnto'] = $this->request->getText( 'returnto' );

            $query = array(
                'client_id' => $wgOAuth2Client['id'],
                'response_type' => 'code',
                'state' => $state,
                'redirect_uri' => $wgServer . str_replace( '$1', 'Special:OAuth2Client/callback', $wgArticlePath )
            );

            $url = $wgOAuth2Client['authorizeUri'].'?'.http_build_query($query);

            $this->output->redirect($url);
        }
    }

    private function _callback(){
        global $wgUser, $wgOAuth2Client;
        global $wgServer, $wgArticlePath;

        if($error = $this->request->getText( 'error' )){
            $this->output->showErrorPage( 'error', 'meet-error', array( $error ) );
            $this->output->addWikiText("或 [[Special:OAuth2Client|重新登录]]");
        }else{
            $state = $this->request->getText( 'state' );
            if(isset($_SESSION['OAuthState']) && $state == $_SESSION['OAuthState']){
                unset($_SESSION['OAuthState']);

                $query = array(
                    'client_id' => $wgOAuth2Client['id'],
                    'client_secret' => $wgOAuth2Client['secret'],
                    'grant_type' => 'authorization_code',
                    'code' => $this->request->getText( 'code' ),
                    'redirect_uri' => $wgServer . str_replace( '$1', 'Special:OAuth2Client/callback', $wgArticlePath )
                );

                $ret = $this->_post($wgOAuth2Client['accessTokenUri'], http_build_query($query));
                $retArray = json_decode($ret, true);

                $accountInfo = $this->_getUsername($retArray);
                if(!$accountInfo['status']){
                    $this->output->showErrorPage( 'error', 'username-error', array( $accountInfo['error'] ) );
                    $this->output->addWikiText("或 [[Special:OAuth2Client|重新登录]]");
                }else{
                    // Get MediaWiki user
                    $u = User::newFromName($accountInfo['username']);

                    // Create a new account if the user does not exists
                    if ($u->getID() == 0) {
                        // Create the user
                        $u->addToDatabase();
                        $u->setRealName($accountInfo['username']);
                        $u->setEmail($accountInfo['email']);
                        $u->setPassword(User::randomPassword()); //PwdSecret is used to salt the username, which is then used to create an md5 hash which becomes the password
                        $u->setToken();
                        $u->saveSettings();

                        // Update user count
                        $ssUpdate = new SiteStatsUpdate(0,0,0,0,1);
                        $ssUpdate->doUpdate();

                        $returnto = wfMessage( 'newuser-guide' )->parse();
                    }

                    // Login successful
                    $u->setCookies();

                    // Redirect if a returnto parameter exists
                    if(!isset($returnto)) $returnto = (!empty($_SESSION['returnto'])) ? $_SESSION['returnto'] : wfMessage( 'MainPage' )->parse();

                    $target = Title::newFromText($returnto);
                    if ($target) {
                        echo 3;
                        $this->output->redirect($target->getFullUrl()."?action=purge"); //action=purge is used to purge the cache.
                        echo 4;
                    }
                }
            }else{
                unset($_SESSION['OAuthState']);

                $this->output->showErrorPage( 'error', 'state-unmatched');
                $this->output->addWikiText("或 [[Special:OAuth2Client|重新登录]]");
            }

        }
    }

    private function _getUsername($array){
        $url = 'http://hometown.scau.edu.cn/bbs/plugin.php?id=iltc_open:userinfo&uid='.$array['uid'];
        $ret = file_get_contents($url);

        $retArray = json_decode($ret, true);

        $data = array();
        if($retArray['status'] == 'success'){
            //TODO:过滤用户组
            if(false){
                $data['error'] = '您没有权限登录此维基';
            }else{
                $data['username'] = $retArray['data']['username'];
                $data['email'] = $retArray['data']['email'];
            }
        }else{
            $data['error'] = '无法获取用户信息';
        }

        $data['status'] = (isset($data['error'])) ? 0 : 1;
        return $data;
    }

    private function _post($url, $post){
        $ch = curl_init();//初始化curl

        curl_setopt($ch,CURLOPT_URL, $url);//抓取指定网页
        curl_setopt($ch, CURLOPT_HEADER, 0);//设置header
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//要求结果为字符串且输出到屏幕上
        curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);//关闭SSL验证
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);//POST数据
        $data = curl_exec($ch);//运行curl
        curl_close($ch);

        return $data;
    }

    static function OAuthEnabled() {
        global $wgOAuth2Client;
        return isset(
            $wgOAuth2Client['id'],
            $wgOAuth2Client['secret'],
            $wgOAuth2Client['authorizeUri'],
            $wgOAuth2Client['accessTokenUri']
        );
    }



    function getGroupName() {
        return 'login';
    }
}