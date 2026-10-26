<?php

/**
 * DouPHP®
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-08
 */

/**
 * 表单预置模板清单。
 *
 * 由 {@see \Dou\Core\Service\Form\FormTemplateRegistry} 直接 include，
 * 后台「新建表单」选择模板后一次性写入整套字段，devtools/form-rebuild.php --seed 亦复用此清单。
 *
 * 字段属性与 dou_form_field 列一一对应；未列出的属性取列默认值。
 */
return array(
    'templates' => array(
        'contact' => array(
            'name' => '业务咨询',
            'content' => '面向潜在客户的基础咨询表单，收集联系人与需求说明。',
            'success_message' => '提交成功，我们会尽快与您联系。',
            'fields' => array(
                array('name' => '联系人', 'slug' => 'contact', 'type' => 'input', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入联系人姓名'),
                array('name' => '电话', 'slug' => 'phone', 'type' => 'tel', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入联系电话'),
                array('name' => '邮箱', 'slug' => 'email', 'type' => 'email', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入邮箱地址'),
                array('name' => '简要说明', 'slug' => 'message', 'type' => 'textarea', 'must_fill' => 1, 'width' => 12, 'placeholder' => '请简要描述您的需求'),
            ),
        ),
        'quote' => array(
            'name' => '产品询价',
            'content' => '产品详情页询价表单，收集产品、联系方式与所在地区。',
            'success_message' => '询价已收到，报价单将发送至您的邮箱。',
            'fields' => array(
                array('name' => '产品名称', 'slug' => 'product', 'type' => 'input', 'must_fill' => 1, 'width' => 12, 'placeholder' => '请输入需要询价的产品'),
                array('name' => '邮箱', 'slug' => 'email', 'type' => 'email', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入邮箱地址'),
                array('name' => '手机号', 'slug' => 'mobile', 'type' => 'tel', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入手机号'),
                array('name' => '所在地区', 'slug' => 'area', 'type' => 'area', 'must_fill' => 1, 'width' => 6),
                array('name' => '留言内容', 'slug' => 'message', 'type' => 'textarea', 'must_fill' => 1, 'width' => 12, 'placeholder' => '请补充采购数量、交付时间等要求'),
            ),
        ),
        'feedback' => array(
            'name' => '在线留言',
            'content' => '通用留言表单，适合放在联系页或全站页脚。',
            'success_message' => '留言已提交，感谢您的反馈。',
            'fields' => array(
                array('name' => '称呼', 'slug' => 'nickname', 'type' => 'input', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入您的称呼'),
                array('name' => '联系方式', 'slug' => 'contact', 'type' => 'input', 'must_fill' => 1, 'width' => 6, 'placeholder' => '手机、邮箱或微信'),
                array('name' => '留言内容', 'slug' => 'message', 'type' => 'textarea', 'must_fill' => 1, 'width' => 12, 'placeholder' => '请输入留言内容'),
            ),
        ),
        'cooperation' => array(
            'name' => '合作加盟',
            'content' => '渠道与合作意向登记表单。',
            'success_message' => '申请已提交，招商专员会与您联系。',
            'fields' => array(
                array('name' => '公司名称', 'slug' => 'company', 'type' => 'input', 'must_fill' => 1, 'width' => 12, 'placeholder' => '请输入公司全称'),
                array('name' => '联系人', 'slug' => 'contact', 'type' => 'input', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入联系人姓名'),
                array('name' => '电话', 'slug' => 'phone', 'type' => 'tel', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入联系电话'),
                array('name' => '合作意向', 'slug' => 'intent', 'type' => 'select', 'must_fill' => 1, 'width' => 6, 'option' => "产品代理\r\n项目合作\r\n渠道分销\r\n其他"),
                array('name' => '详细说明', 'slug' => 'message', 'type' => 'textarea', 'must_fill' => 0, 'width' => 12, 'placeholder' => '请说明合作背景与预期'),
            ),
        ),
        'resume' => array(
            'name' => '简历投递',
            'content' => '招聘页简历投递表单，支持简历附件上传。',
            'success_message' => '简历已收到，我们会尽快安排面试。',
            'fields' => array(
                array('name' => '姓名', 'slug' => 'real_name', 'type' => 'input', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入真实姓名'),
                array('name' => '电话', 'slug' => 'phone', 'type' => 'tel', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入联系电话'),
                array('name' => '邮箱', 'slug' => 'email', 'type' => 'email', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入邮箱地址'),
                array('name' => '应聘岗位', 'slug' => 'position', 'type' => 'input', 'must_fill' => 1, 'width' => 6, 'placeholder' => '请输入应聘岗位'),
                array('name' => '简历附件', 'slug' => 'resume_file', 'type' => 'file', 'must_fill' => 1, 'width' => 12, 'limit' => 1, 'extensions' => 'pdf,doc,docx', 'max_size' => 5120, 'tip' => '支持 pdf、doc、docx，单文件不超过 5MB'),
                array('name' => '自我介绍', 'slug' => 'intro', 'type' => 'textarea', 'must_fill' => 0, 'width' => 12, 'placeholder' => '请简单介绍您的经历'),
            ),
        ),
    ),
);
