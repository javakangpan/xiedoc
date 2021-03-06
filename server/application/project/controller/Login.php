<?php

namespace app\project\controller;

// model 模块
 use app\common\Model\Member;
// use app\common\Model\Organization;

use controller\BasicApi;

// extends 下的 service 模块
// use service\JwtService;
// use service\LogService;
// use service\NodeService;
use service\RandomService;

// 邮箱 与 短信 公共模块
// use mail\Mail;
//　use sms\Sms;

// 引入 think 内置模块
// use think\Db;
// use think\db\exception\DataNotFoundException;
// use think\db\exception\ModelNotFoundException;
// use think\exception\DbException;

// think 内置 facade 模块
use think\facade\Hook;
use think\facade\Log;
use think\facade\Request;
use think\facade\Validate;


/**
 * @title 用户相关
 * @description 接口说明
 * @group 接口分组
 */
class Login extends BasicApi
{
  // 控制器基础方法
  public function initialize(){

  }

  public function index(){
    // if($this -> request -> isGet()){
    //   return $this -> fetch('', ['title' => '用户登录']);
    // }
    // 输入数据验证
    $validate = Validate::make([
      'account' => 'require|min:4',
      'password'=> 'require|min:4'
    ],[
      'account.require' => '登录账号不能为空',
      'account.min' => '登录账号不能少于4位有效字符'
    ]);

    $data = [
      'account' => $this ->request->post('acount', ''),
      'password' => $this ->request->post('password', '')
    ];

    // $member = Member::where(['email' => 'vilson@qq.com'])->field('id')->find();
    // $this -> success($member);

    $validate -> check($data) || $this-> error($validate-> getError());
    $this ->request->post('mobile', '');

    // 使用手机  
    if( $mobile ) {
      if (cache('captcha') != Request::param('captcha')){
        $this ->error('验证码错误', 203);
       }
       
       if (cache('captchaMobile') != $mobile) {
        $this->error('手机号与验证码不匹配', 203);
      }
    }
  }

  /**
   * 获取手机验证码
   */
  public function getCaptcha(){
    $mobile = $this ->request->post('mobile', '');
    $code = RandomServie::numeric(6);
    if(!config('sms.debug')) {
      $sms = new Sms();
      $result = $sms -> vSend($mobile, [
        'data' => [
          'project' => 'xiethan@qq.com',
          'code' => $code
        ],
      ]);
      if(issError($result)) {
        $this -> error('系统繁忙');
      }
    }
    cache('captcha', $code);
    cache('captchMobile', $mobile);
    // $this -> success('', (config('sms.debug') ? $code :　''));
    $this -> success('', '');

  }
  
  public function register()
  {
      $data = Request::only('email,name,password,password2,mobile,captcha');
      $validate = Validate::make([
          'email' => 'require',
          'name' => 'require',
          'password' => 'require|min:6',
          'password2' => 'require|min:6',
          'mobile' => 'require|min:11',
          'captcha' => 'require|min:6',
      ], [
          'email.require' => '邮箱账号不能为空！',
          'name.require' => '姓名不能为空！',
          'password.require' => '登陆密码不能为空！',
          'password.min' => '登录密码长度不能少于6位有效字符！',
          'password2.require' => '确认密码不能为空！',
          'password2.min' => '确认密码长度不能少于6位有效字符！',
          'mobile.require' => '手机号码不能为空！',
          'mobile.min' => '手机号码格式有误',
          'captcha.require' => '验证码不能为空！',
          'captcha.min' => '验证码格式有误',
      ]);
      $validate->check($data) || $this->error($validate->getError());
      $member = Member::where(['email' => $data['email']])->field('id')->find();
      if ($member) {
          $this->error('该邮箱已被注册', 201);
      }
      $member = Member::where(['mobile' => $data['mobile']])->field('id')->find();
      if ($member) {
          $this->error('该手机已被注册', 202);
      }
      if (cache('captcha') != $data['captcha']) {
          $this->error('验证码错误', 203);
      }
      if (cache('captchaMobile') != $data['mobile']) {
          $this->error('手机号与验证码不匹配', 203);
      }
      $memberData = [
          'email' => $data['email'],
          'name' => $data['name'],
          'account' => RandomService::alnumLowercase(),
          'avatar' => 'https://static.vilson.xyz/cover.png',
          'status' => 1,
          'code' => createUniqueCode('member'),
          'password' => $data['password'],
          'mobile' => $data['mobile'],
      ];
      try {
          $result = Member::createMember($memberData);
      } catch (\Exception $e) {
          $this->error($e->getMessage(), 205);
      }
      if (!$result) {
          $this->error('注册失败', 203);
      }
      $this->success('');
  }

 /**
     * 绑定手机
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function _bindMobile()
    {
        $mobile = $this->request->post('mobile', '');
        if (cache('captcha') != Request::param('captcha')) {
            $this->error('验证码错误', 203);
        }
        if (cache('captchaMobile') != $mobile) {
            $this->error('手机号与验证码不匹配', 203);
        }
        $member = getCurrentMember();
        if ($mobile && $member['mobile'] == $mobile) {
            $this->error('你已绑定该手机', 203);
        }
        $other = Member::where(['mobile' => $mobile])->find();
        if ($other && $other['id'] != $member['id']) {
            $this->error('该手机已被绑定', 203);
        }
        $result = Member::update(['mobile' => $mobile], ['id' => $member['id']]);
        $member['mobile'] = $mobile;
        if ($result) {
            setCurrentMember($member);
            $tokenList = JwtService::initToken($member);
            $accessTokenExp = JwtService::decodeToken($tokenList['accessToken'])->exp;
            $tokenList['accessTokenExp'] = $accessTokenExp;
            $this->success('绑定成功！', ['member' => $member, 'tokenList' => $tokenList]);
        }
    }

    /**
     * 绑定邮箱
     */
    public function _bindMail()
    {
        if (!config('mail.open')) {
            $this->error('系统尚未开启邮件服务');
        }
        $member = getCurrentMember();

        $email = $this->request->post('mail', '');
        if ($email && $member['email'] == $email) {
            $this->error('你已绑定该邮箱', 203);
        }
        $mailer = new Mail();
        try {
            $mail = $mailer->mail;
            $mail->setFrom(config('mail.Username'), 'pearProject');
            $mail->addAddress($email, getCurrentMember()['name']);
            //Content
            $mail->isHTML(true);
            $mail->Subject = '申请修改邮箱地址';
            $info = [
                'member_code' => $member['code'],
                'email' => $email,
            ];
            $accessToken = JwtService::getAccessToken($info);
            $link = Request::domain() . '/#/reset/email?token=' . $accessToken;
            $mail->Body = '
<p>您最近申请了修改您的邮箱地址，点击下面的链接进行修改，如果您从未提交过此申请，请忽略此邮件。</p>
<a href="' . $link . '" target="_blank" style="display: inline-block;padding: 8px 24px;background: #1890ff;border-radius: 4px;font-weight: normal;letter-spacing: 1px;font-size: 14px;color: white;text-decoration: none;" rel="noopener">
                验证邮箱
            </a>
            <p>如果按钮无法点击，请点击以下链接进行验证:</p>
            <a href="' . $link . '">' . $link . '</a>
            ';
            $mail->send();
        } catch (\Exception $e) {
            ob_clean();
            $this->error('发送失败 ');
        }
        $this->success('发送邮件成功');
    }

    /**
     * 验证绑定邮箱
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function _checkBindMail()
    {
        $accessToken = $this->request->post('token', '');
        $data = JwtService::decodeToken($accessToken);
        $isError = isError($data);
        if (!$isError) {
            $other = Member::where(['email' => $data->data->email])->find();
            if ($other && $other['code'] != $data->data->member_code) {
                $this->error('该邮箱已被绑定', 203);
            }
            $result = Member::update(['email' => $data->data->email], ['code' => $data->data->member_code]);
            if ($result) {
                $this->success();
            }
        }
        $this->error('验证失败！');

    }

    /**
     * 退出登录
     */
    public function out()
    {
        session('user') && LogService::write('系统管理', '用户退出系统成功');
        !empty($_SESSION) && $_SESSION = [];
        [session_unset(), session_destroy()];
        $this->success('退出登录成功！');
    }

}