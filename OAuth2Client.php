<?php
class OAuth2Client {
    static function interceptionLogin(){
        global $wgRequest;
        global $wgOut;
        global $wgServer, $wgArticlePath;
        global $wgLanguageCode;

        $lg = Language::factory($wgLanguageCode);

        $title = $wgRequest->getVal('title');
        if($title == $lg->specialPage("Userlogin") || $title == $lg->specialPage("CreateAccount")) {
            $url = $wgServer . str_replace( '$1', 'Special:OAuth2Client?returnto='.$wgRequest->getVal('returnto'), $wgArticlePath );
            $wgOut->redirect($url);
        }
    }
}