# Host: localhost  (Version: 5.7.26)
# Date: 2026-02-15 19:57:16
# Generator: MySQL-Front 5.3  (Build 4.234)

/*!40101 SET NAMES utf8 */;

#
# Structure for table "groups"
#

DROP TABLE IF EXISTS `groups`;
CREATE TABLE `groups` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `groupName` varchar(255) DEFAULT NULL COMMENT '组名',
  `groupLeader` varchar(255) DEFAULT NULL COMMENT '组长Id',
  PRIMARY KEY (`Id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COMMENT='小组信息表';

#
# Structure for table "homework"
#

DROP TABLE IF EXISTS `homework`;
CREATE TABLE `homework` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `teacherid` int(11) DEFAULT NULL COMMENT '布置作业的教师ID',
  `isforallstudents` tinyint(1) DEFAULT NULL COMMENT '是否选做',
  `submit` tinyint(1) DEFAULT NULL COMMENT '是否通过平台提交',
  `releasetime` int(11) DEFAULT NULL COMMENT '作业发布时间',
  `stoptime` int(11) DEFAULT NULL COMMENT '截止时间',
  `description` varchar(255) DEFAULT NULL COMMENT '作业描述',
  `title` varchar(255) DEFAULT NULL COMMENT '作业标题',
  PRIMARY KEY (`Id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8 COMMENT='家庭作业';

#
# Structure for table "homeworkcheck"
#

DROP TABLE IF EXISTS `homeworkcheck`;
CREATE TABLE `homeworkcheck` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `submissionid` int(11) NOT NULL COMMENT '对应提交记录ID',
  `teacherid` int(11) DEFAULT NULL COMMENT '教师ID',
  `score` varchar(10) DEFAULT NULL COMMENT '分数',
  `content` text COMMENT '详细评语',
  `check_image` longblob COMMENT '如果有手写批改图，可以存这里',
  `createtime` int(11) DEFAULT NULL COMMENT '批改时间',
  PRIMARY KEY (`Id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='老师批改记录';

#
# Structure for table "homeworksubmission"
#

DROP TABLE IF EXISTS `homeworksubmission`;
CREATE TABLE `homeworksubmission` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `studentid` int(11) DEFAULT NULL COMMENT '学生ID',
  `homeworkid` int(11) DEFAULT NULL COMMENT '家庭作业ID',
  `submission` longblob COMMENT '提交的图片',
  `time` int(11) DEFAULT NULL COMMENT '第一次提交时间',
  `updatetime` int(11) DEFAULT NULL COMMENT '更新时间（如果没有更新过就是初次提交时间）',
  PRIMARY KEY (`Id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8 COMMENT='学生作业提交';

#
# Structure for table "scorechangelog"
#

DROP TABLE IF EXISTS `scorechangelog`;
CREATE TABLE `scorechangelog` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `teacherid` int(11) DEFAULT NULL COMMENT '修改量化评分的教师ID',
  `reason` varchar(255) DEFAULT NULL COMMENT '扣分/加分项ID，自定义则为“custom-<原因>”',
  `change` double DEFAULT NULL COMMENT '加分/扣分量',
  `timestamp` int(11) DEFAULT NULL COMMENT '操作时间',
  `studentid` varchar(255) DEFAULT NULL COMMENT '学生id',
  PRIMARY KEY (`Id`)
) ENGINE=MyISAM AUTO_INCREMENT=24 DEFAULT CHARSET=utf8 COMMENT='量化评分改动记录';

#
# Structure for table "scorechangetype"
#

DROP TABLE IF EXISTS `scorechangetype`;
CREATE TABLE `scorechangetype` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL COMMENT '加分/减分项名',
  `change` double DEFAULT NULL COMMENT '量化评分改动量',
  `timestamp` int(11) DEFAULT NULL COMMENT '时间戳',
  PRIMARY KEY (`Id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8 COMMENT='量化评分加分/减分项';

#
# Structure for table "screens"
#

DROP TABLE IF EXISTS `screens`;
CREATE TABLE `screens` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `password` varchar(255) DEFAULT NULL COMMENT '密码(password_hash)',
  `token` varchar(255) DEFAULT NULL COMMENT '密钥',
  `tokenexpire` int(11) DEFAULT NULL COMMENT '密钥过期时间',
  PRIMARY KEY (`Id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COMMENT='班级大屏信息';

#
# Structure for table "students"
#

DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `firstname` varchar(255) DEFAULT NULL COMMENT '学生名',
  `lastname` varchar(255) DEFAULT NULL COMMENT '学生姓',
  `groupId` int(11) DEFAULT NULL COMMENT '量化评分小组',
  `token` varchar(255) DEFAULT NULL COMMENT '密钥',
  `tokenExpire` int(11) DEFAULT NULL COMMENT '密钥过期时间',
  `score` double DEFAULT NULL COMMENT '量化评分',
  `password` varchar(255) DEFAULT NULL COMMENT '密码',
  PRIMARY KEY (`Id`)
) ENGINE=MyISAM AUTO_INCREMENT=50 DEFAULT CHARSET=utf8 COMMENT='学生信息表';

#
# Structure for table "teachers"
#

DROP TABLE IF EXISTS `teachers`;
CREATE TABLE `teachers` (
  `Id` int(11) NOT NULL AUTO_INCREMENT COMMENT '教师/管理员ID',
  `firstname` varchar(255) DEFAULT NULL COMMENT '名',
  `lastname` varchar(255) DEFAULT NULL COMMENT '姓',
  `subject` varchar(255) DEFAULT NULL COMMENT '任教科目',
  `isAdmin` tinyint(1) DEFAULT NULL COMMENT '是否为管理员',
  `password` varchar(255) DEFAULT NULL COMMENT '密码',
  `token` varchar(255) DEFAULT NULL COMMENT '私钥',
  `tokenExpire` int(11) DEFAULT NULL COMMENT '私钥过期时间',
  PRIMARY KEY (`Id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8 COMMENT='教师及管理员信息表';
