<?php
/**
 * phpThumbOn
 * Создание превьюх картинок
 *
 * Copyright 2013 by Agel_Nash <Agel_Nash@xaker.ru>
 *
 * @category images
 * @license GNU General Public License (GPL), http://www.gnu.org/copyleft/gpl.html
 * @author Agel_Nash <Agel_Nash@xaker.ru>
 * @package phpThumbOn
 *
 * I love chaining:-)
 */
class phpThumbOn {
    /** @var modX объект класса modX */
    protected $modx;

    /** @var phpThumb объект класса phpThumb */
    protected $_phpThumb = null;

    /** @var null информация об обрабатываемом файле */
    protected $_fileInfo = null;

    /** @var bool наличие ошибки */
    protected $_flag = true;

    /** @var array все настройки класса */
    protected $_config = array();

    /** @var array основные настройки класса */
    private $_cfg = array();

    /** @var array кеш со */
    private $_cacheDir = array();

    /** @var array хранит данные (headers) об удаленном файле */
    private $remoteFileData = array();

    /** @var null|modSnippet Сниппет для подготовки имени файла превьюхи*/
    public $snippetGetCacheName = null;

    /** качество картинки по умолчанию */
    const DEFAULT_QUALITY = '96';

    /** тип файла по умолчанию */
    const DEFAULT_EXT = 'jpeg';

    /** Класс очереди */
    const QueueClass = 'QueueThumb';

    /** @var array константа */
    private static $ALLOWED_EXT = array(
        'jpg'=>true,
        'jpeg'=>true,
        'png'=>true,
        'gif'=>true,
        'bmp'=>true
    );

    /**
     * Проверка резрешено ли создавать такие файлы
     *
     * @param string расширение файла
     * @return array константа ALLOWED_EXT
     */
    public static function ALLOWED_EXT($ext=null){
        if(isset($ext)){
            if(is_scalar($ext) && isset(self::$ALLOWED_EXT[$ext])){
                $out = self::$ALLOWED_EXT[$ext];
            } else{
                $out = false;
            }
        } else{
            $out = self::$_ALLOWED_EXT;
        }

        return $out;
    }

    /**
     * @param modX $modx
     * @param array $config
     * @access public
     */
    public function __construct(modX $modx, array $config = array()) {
        $this->modx = &$modx;

        $corePath = $this->modx->getOption('phpthumbon.core_path',array(),$this->modx->getOption('core_path').'components/phpthumbon/');
        $assetsPath = $this->modx->getOption('assets_path');
        $assetsUrl = $this->modx->getOption('assets_url');

        $this->_cfg = $this->_config = array_merge(array(
            'input' => null,
            'options' => null,

            //Проверка на локальный или удаленный файл
            'localFile' => false,

            //путь к папке с компонента
            'corePath' => $corePath,

            //путь к папке с моделью
            'modelPath' => $corePath.'model/',

            //Папка assets относительно корня сервера
            'assetsPath' => $assetsPath,

            //папка assets относительно web-root директории
            'assetsUrl' => $assetsUrl,

            // Папка в которой будут храниться кешированные картинки
            'cacheDir' => $assetsPath.trim($this->modx->getOption('phpthumbon.cache_dir', $config, 'cache_image'),'/'),

            // подпапка из assets, в которой будут храниться картинки и загрузка картинок в которую будет сопровождаться плагином отчистки кеша
            'imagesFolder' => trim($this->modx->getOption('phpthumbon.images_dir', $config, 'images'),'/'),

            //качество картинки по умолчанию
            'quality' => $this->modx->getOption('phpthumbon.quality', $config, self::DEFAULT_QUALITY),

            //Тип картинки по умолчанию
            'ext' => $this->modx->getOption('phpthumbon.ext', $config, self::DEFAULT_EXT),

            //Права на только что созданный файл
            'new_file_permissions' => (int)$this->modx->getOption('new_file_permissions',$config,'0664'),

            //Очередь
            'queue' => $this->modx->getOption('phpthumbon.queue', $config, 0),

            //Что будет возвращено, если произошла ошибка
            'error_mode' => $this->modx->getOption('phpthumbon.error_mode', $config, 1),

            //Класс очереди
            'queueClassPath' => $this->modx->getOption('phpthumbon.queue_classpath', $config, rtrim($corePath,'/').'/queue/QueueThumb.class.php'),

            //Где хранить уже сжатые noimage файлы
            'noimage_cache' => $this->modx->getOption('phpthumbon.noimage_cache', $config, rtrim($assetsUrl,'/').'/components/phpthumbon/cache/'),

            // картинки нет
            'noimage' => $this->modx->getOption('phpthumbon.noimage', $config, rtrim($assetsUrl,'/').'/components/phpthumbon/noimage.jpg'),

            'makeCacheName' => $this->modx->getOption('phpthumbon.make_cachename', $config, ''),
        ),$config);

        $this->_cfg['options'] = null;
        $this->_cfg['input'] = null;

        $this->_validateConfig();
    }

    /** Полный массив с конфигом */
    public function getConfig(){
        return $this->_config;
    }
    /**
     * Загрузка ресайзера и сжатие картинки
     *
     * @param $from оригинальная картинка
     * @param $to куда сохранять
     * @return bool
     */
    public function loadResizer($from, $to){
        $out = false;
        $new = true;
        if (!$this->modx->loadClass('phpthumb',$this->modx->getOption('core_path').'model/phpthumb/',true,true)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] Could not load phpthumb class');
            $this->_flag = false;
        }
        if($this->_flag){
            $this->_phpThumb = new phpthumb();
            $this->_phpThumb->setSourceFilename($from);
            $this->saveOptions();

            // Пробуем создать превьюху
            if ($this->_phpThumb->GenerateThumbnail()){
                // Сохраняем превьюху в кеш-файл
               if($this->_phpThumb->RenderToFile($to)){
                   $out = $to;
                   chmod($out, octdec($this->_config['new_file_permissions']));
               }else{
                   $new = false;
                   $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] Could not save thumbnail '.$to);
               }
            }else{
                $new = false;
                $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] Could not generate thumbnail '.$from);
            }
        }
        if(!$out){
            $out = $this->getErrorImage($from, $to);
        }
        return $out;
    }

    /**
     * Обработка ошибочных картинок
     *
     * @param $from источник (ошибочная картинка)
     * @param $to получатель (куда должны были сохранить превьюху)
     * @return bool
     */
    protected function getErrorImage($from, $to){
        $out = false;
        $method = $this->_config['error_mode'];
        switch($method){
            case 2:{ /** Обработка оригинальной картинки */
                if(copy($from, $to)){
                    chmod($to, octdec($this->_config['new_file_permissions']));
                    $out = $to;
                }else{
                    $out = $from;
                }
                break;
            }
            case 1:
            default:{ /** Обработка noimage картинки */
                $noImg = $this->_config['noimage'];
                $out = ($from != $noImg) ? $this->loadResizer($noImg, $to) : $noImg;
                break;
            }
        }
        return $out;
    }

    /**
     * Получение значений из локального конфига
     *
     * @param string $key
     * @param null $default
     * @return null|string
     */
    public function getOption($key, $default = null){
        return (is_scalar($key) && isset($this->_config[$key])) ? $this->_config[$key] : $default;
    }

    /**
     * Проверка и исправление настроек по умолчанию
     *
     * @return bool были ли ошибки в конфигурации по умолчанию
     */
    private function _validateConfig(){
        $flag = true;

        if(!empty($this->_config['input'])){
            $path = html_entity_decode($this->_config['input']);

            $path = preg_replace("#^/#","",$path);
            if (!preg_match("/^http(s)?:\/\/\w+/",$path) && strpos($path, ltrim(MODX_BASE_PATH,'/')) === false) {
                $this->_config['localFile'] = true;
                $this->_config['input'] = MODX_BASE_PATH . $path;
            }else{
                $this->_config['input'] = $path;
            }
        }

        if(!isset($this->_config['ext']) || !self::ALLOWED_EXT($this->_config['ext'])){
            $this->_config['ext'] = self::DEFAULT_EXT;
            $flag = false;
        }

        if(!isset($this->_config['quality']) || (int)$this->_config['quality']<=0){
            $this->_config['quality'] = self::DEFAULT_QUALITY;
            $flag = false;
        }
        return $flag;
    }

    /**
     * Обновление настроек класса
     *
     * @param array $options настройки для перезагрузки
     * @access private
     * @return bool статус обновления конфигов
     */
    private function _setConfig($options = array()){
        if($flag = (is_array($options) && array()!=$options)){
            $this->_config = array_merge($this->_cfg,$options);
            $this->_validateConfig();
        }
        return $flag;
    }

    /**
     * Запуск процесса создания превьюхи
     *
     * @param array обновление конфигов без перезагрузки класса
     * @access public
     * @return string имя файла с превьюхой или пустая строка
     */
    public function run($options = array()){
        $this->_setConfig($options);
        if($this->_flag){
            $out = $this->relativeSrcPath()
                ->makeCacheDir()
                ->makeOptions()
                ->checkOptions()
                ->getCacheFileName()
                ->getThumb();
        }else{
            $out = '';
        }
        $this->flush();
        return $out;
    }

    /**
     * Цепочка для проверки необходимых параметров
     *
     * @access public
     * @return $this
     */
    public function checkOptions(){
        $this->checkExt()
            ->checkQuality();
        return $this;
    }

    /**
     * Создаем папку в которой будут храниться превьюхи текущей картинки
     *
     * @access public
     * @return $this
     */
    public function makeCacheDir(){
        $exists = false;
        $this->_config['_cachePath'] = $this->_config['cacheDir'].'/'.$this->_config['relativePath'];

        if($this->_flag){
            if($exists = isset($this->_cacheDir[$this->_config['_cachePath']])){
                $this->_flag = $this->_cacheDir[$this->_config['_cachePath']];
            }
        }
        if($this->_flag && !$exists){
            $this->_cacheDir[$this->_config['_cachePath']] = $this->_flag = $this->makeDir($this->_config['_cachePath']);
        }
        return $this;
    }

    /**
     * Проверка на существование удаленного файла
     */
    private function _remote_file_exists($url){
        return(bool)preg_match('~HTTP/1\.\d\s+200\s+OK~', @current(get_headers($url)));
    }

    /**
     * Проверяем имя и его существование.
     * Затем определяем папку в которую будут складываться превьюхи этого файла
     *
     * @access public
     * @return $this
     */
    public function relativeSrcPath(){
        if(!(empty($this->_config['input']) || !is_scalar($this->_config['input']))){
            if (preg_match("/^http(s)?:\/\/\w+/",$this->_config['input']) && $this->_remote_file_exists($this->_config['input'])){
                $this->_config['relativePath'] = "external_url";
            }
            elseif (file_exists($this->_config['input'])){
                $full_assets = $this->_config['assetsPath'];
                $assets = ltrim($this->_config['assetsUrl'],'/');
                $imgDir = $this->_config['imagesFolder'];
                $this->_config['relativePath'] = preg_replace("#^({$full_assets}|(/)?{$assets})(/)?{$imgDir}(/)?|".MODX_BASE_PATH."#", '', $this->_pathinfo('dirname'));
            }
        }else{
            if($this->_flag = file_exists($this->_config['noimage'])){
                $this->_config['input'] = $this->_config['noimage'];
                $this->_config['relativePath'] = '';
            }else{
                $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] Input image path is empty');
            }
        }
        return $this;
    }

    /**
     * Удаляем из имени файла мусор
     *
     * @param string $string Any string with MODX tags
     *
     * @return string String with html entities
     */
    public function sanitizeFileName($str = '') {
        // $str = 'https://www.ya. @ ru/1231.com';
        $str = htmlentities(trim($str), ENT_QUOTES, "UTF-8");
        $str = preg_replace('/^http(s)?:\/\/(www)?(\.)*/', '', $str);
        $str = preg_replace('/\./', '_', $str);
        $str = preg_replace('/\//', '_', $str);
        $str = preg_replace("/[^A-Za-z0-9._]/", "", $str);
        return $str;
    }

    private function _fileExt($contentType){
        $map = array(
            // 'application/pdf'   => '.pdf',
            // 'application/zip'   => '.zip',
            // 'text/css'          => '.css',
            // 'text/html'         => '.html',
            // 'text/javascript'   => '.js',
            // 'text/plain'        => '.txt',
            // 'text/xml'          => '.xml',
            'image/gif'           => 'gif',
            'image/jpeg'          => 'jpg',
            'image/png'           => 'png',
            'image/x-windows-bmp' => 'bmp',
            'image/x-icon'        => 'ico',
        );
        if (isset($map[$contentType]))
        {
            return $map[$contentType];
        }else{
            return '';
        }
    }

    /**
     * так как родная функция pathinfo() с utf работает крайне криво
     * то заменим ее на аналог
     * @param  [type] $path_file [description]
     * @return [type]       [description]
     */
    private function _pathinfoUTF($path_file){
        $result=array();
        // $url = "http://www.cyberforum.ru/newreply2.php?do=newreply&noquote=1&p=2901895";
        // $url = "////www.cyberforum.ru/xx/bbb. _=x/ccc/newr _+= \eply.zz.2.php";
        $path_file = "/".ltrim($path_file,"/");
        $path_parse = parse_url($path_file);

        preg_match("#[^/]*$#i", $path_parse['path'], $match);
        preg_match("~[^/]+$~",$path_parse['path'],$file);
        preg_match("~([^/]+)[.$]+(.*)~",$file[0],$file_ext);

        $result['dirname'] = pathinfo($path_file, PATHINFO_DIRNAME);
        $result['basename'] = $file_ext[0];
        $result['filename'] = $file_ext[1]; // $match[0]
        $result['extension'] = $file_ext[2];

        return $result;
    }


    /**
     * Чтобы не дергать постоянно файл который обрабатываем
     *
     * @access private
     * @param string $name ключ
     * @return string информация из pathinfo о обрабатываемом файле input
     */
    private function _pathinfo($name){
        if(empty($this->_fileInfo)){
            // $this->_fileInfo = pathinfo($this->_config['input']);
            $this->_fileInfo = $this->_pathinfoUTF($this->_config['input']);
            // if (empty($this->_fileInfo['filename']) && !$this->_config['localFile']){
            if (!$this->_config['localFile']){
                // это значит что файл удаленный и нам нужно состряпать для него временное имячко
                $this->_fileInfo['filename']=$this->sanitizeFileName($this->_config['input']);
                // $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] pathinfo:'.print_r($this->_fileInfo,true));
                // нужно получить расширение файла, может из header???
                // $ext = $this->_pathinfo('extension');
                $remoteFileData=$this->_remoteFileData($this->_config['input']);
                $this->_fileInfo['extension'] = $this->_fileExt($remoteFileData['content-type']);
            }
        }
        $out = is_scalar($name) && isset($this->_fileInfo[$name]) ? $this->_fileInfo[$name] : '';
        return $out;
    }


    /**
     * Формируем массив параметров для phpThumb из параметра options у сниппета
     *
     * @access public
     * @return $this
     */
    public function makeOptions(){
        if($this->_flag){
            if(!$this->CheckQueue() && is_array($this->_config['options'])){
                $this->_config['_options'] = $this->_config['options'];
            }else{
                $eoptions = is_array($this->_config['options']) ? $this->_config['options'] : explode('&',$this->_config['options']);
                $this->_config['_options'] = array();
                foreach ($eoptions as $opt) {
                    $opt = explode('=',$opt);
                    $key = str_replace('[]','',$opt[0]);
                    if (!empty($key)) {
                        /* allow arrays of options */
                        if (isset($this->_config['_options'][$key])) {
                            if (is_string($this->_config['_options'][$key])) {
                                $this->_config['_options'][$key] = array($this->_config['_options'][$key]);
                            }
                            $this->_config['_options'][$key][] = $opt[1];
                        } else { /* otherwise pass in as string */
                            $this->_config['_options'][$key] = $opt[1];
                        }
                    }
                }
            }
            //Параметр src игнорируется. Мы работаем только с input
            if(isset($this->_config['_options'],$this->_config['_options']['src'])){
                unset($this->_config['_options']['src']);
            }
        }
        return $this;
    }

    /**
     * Если параметр с расширением файла не установлен
     *
     * @access public
     * @return $this
     */
    public function checkExt(){
        if ($this->_flag && empty($this->_config['_options']['f'])){
            $ext = $this->_pathinfo('extension');
            $ext = strtolower($ext);
            $this->_config['_options']['f'] = self::ALLOWED_EXT($ext) ? $ext : $this->_config['ext'];
        }
        return $this;
    }

    /**
     * Если качество картинки не установлено
     *
     * @access public
     * @return $this
     */
    public function checkQuality(){
        if($this->_flag && empty($this->_config['_options']['q'])){
            $this->_config['_options']['q'] = $this->_config['quality'];
        }
        return $this;
    }

    /**
     * Все необходимые параметры для создания превьюхи определены. Поэтому передаем их в phpthumb
     *
     * @access public
     * @return $this
     */
    public function saveOptions(){
        if($this->_flag){
            foreach($this->_config['_options'] as $item=>$value){
                $this->_phpThumb->setParameter($item,$value);
            }
        }
        return $this;
    }

    /**
     * Определяем имя файла и проверяем его наличие в кеш-папке
     *
     * @access public
     * @return $this
     */
    public function getCacheFileName(){

        if($this->_flag){
            $filename = $this->_pathinfo('filename');

            if(!empty($this->_config['makeCacheName']) && empty($this->snippetGetCacheName)){
                $this->snippetGetCacheName = $this->modx->getObject('modSnippet', array('name' => $this->_config['makeCacheName']));
            }
            if(empty($this->snippetGetCacheName)){
                //папка в которой лежат превьюхи текущей картинки
                $cacheFileDir = rtrim($this->_config['_cachePath'],'/').'/'.$filename;

                //Для поиска других превьюх с этого же файла
                //glob("fullfolder_to_cache_image/filename_[0-9]*x[0-9]*_???.{jpeg,gif,bmp,jpg,png}",GLOB_BRACE)
                $this->_config['_globThumb'] = $cacheFileDir."_[0-9]*x[0-9]*_???.{jpeg,gif,bmp,jpg,png}";

                $w = isset($this->_config['_options']['w']) ? $this->_config['_options']['w'] : 0;
                $h = isset($this->_config['_options']['h']) ? $this->_config['_options']['h'] : 0;

                //Уникальный суффикс в имени файла превьюхи
                $this->_config['_cacheSuffix'] = $w.'x'.$h.'_'.substr(md5(serialize($this->_config['_options'])),0,3);

                //Кеш файл превьюхи
                $this->_config['_cacheFileName'] = $cacheFileDir . "_". $this->_config['_cacheSuffix'] . "." . $this->_config['_options']['f'];
                // $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] config:'.print_r($this->_config,true));
                //}
            }else{
                $this->snippetGetCacheName->_cacheable = false;
                $this->snippetGetCacheName->_processed = false;

                $out = $this->snippetGetCacheName->process(array(
                    'phpThumbOn' => $this,
                    'filename' => $filename
                ));
                $out = unserialize($out);

                $this->_config['_cacheFileName'] = isset($out['_cacheFileName']) ? $out['_cacheFileName'] : '';
                $this->_config['_cacheSuffix'] = isset($out['_cacheSuffix']) ? $out['_cacheSuffix'] : '';
                $this->_config['_globThumb'] = isset($out['_globThumb']) ? $out['_globThumb'] : '';
            }
        }
// $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] CFN:'.$this->_config['_cacheFileName']);
        return $this;
    }

    /**
     * Загрузка класса для обработки очередей
     *
     * @return bool
     */
    protected function CheckQueue(){
        $flag = false;
        if(!empty($this->_config['queue']) && file_exists($this->_config['queueClassPath'])){
            include_once($this->_config['queueClassPath']);
            if(class_exists(phpThumbOn::QueueClass)){
                $flag = true;
            }
        }
        return $flag;
    }

    protected function _remoteFileData($f) {
        if (!empty($this->remoteFileData)) return $this->remoteFileData;
        // $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] FILE:'.$f);

        $h = @get_headers($f, 1);
        if (stristr($h[0], '200')) {
            foreach($h as $k=>$v) {
                $result[strtolower(trim($k))]=$v;
                // if(strtolower(trim($k))=="last-modified") {
                    // $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] FILE:'.$f);
                    // $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] DATE:'.$v);    // Thu, 07 Mar 2013 19:58:24 GMT
                    // return strtotime($v);
                // }
            }
            $this->remoteFileData=$result;
        }
        return 0;
    }

    /**
     * Пытаемся создать превьюху
     *
     * @access public
     * @return string путь к превьюхе или пустая строка
     */
    public function getThumb(){
        if($this->_flag){
            $out = $this->_config['_cacheFileName'];
            $cacheNoImage = $this->_config['noimage_cache'] . $this->_config['_cacheSuffix'] . "." . $this->_config['_options']['f'];

            if($this->_config['input'] == $this->_config['noimage']){
                $this->copyFile($cacheNoImage, $out);
            }else{
                // if(file_exists($out) && filemtime($out) < filemtime($this->_config['input'])){
                // т.к. мы не можем определить время файла на другом хосте через filemtime

                /*if(!$this->_config['localFile'])
                {
                    $this->_remoteFileData($this->_config['input']);
                }*/
                // if((file_exists($out) && !$this->_config['localFile']) || ($this->_config['localFile'] && file_exists($out) && filemtime($out) < filemtime($this->_config['input']))){

                // $remoteFileData=$this->_remoteFileData($this->_config['input']);
                if((file_exists($out) && !$this->_config['localFile'] && filemtime($out) < strtotime($remoteFileData['last-modified']) && $remoteFileData=$this->_remoteFileData($this->_config['input']) ) || ($this->_config['localFile'] && file_exists($out) && filemtime($out) < filemtime($this->_config['input']))){
                    /** Удаляем существующие превьюхи этого файла */
                    $thumbFile = glob($this->_config['_globThumb'], GLOB_BRACE);
                    foreach($thumbFile as $tf){
                        unlink($tf);
                    }
                }
            }

            if(!file_exists($out)){
                if($this->CheckQueue()){
                    $class = phpThumbOn::QueueClass;
                    $out = $class::add($this, $this->modx);
                }else{
                    $out = $this->loadResizer($this->_config['input'], $out);
                    if($this->_config['input'] == $this->_config['noimage']){
                        $this->copyFile($out, $cacheNoImage);
                    }
                }
            }
        }else{
            $out = false;
        }

        if($out===false){
            $out = '';
        }else{
            $out = str_replace($this->_config['assetsPath'], $this->_config['assetsUrl'], $out);
        }
        return $out;
    }

    /**
     * Копирование файла с проверкой на существование оригинального файла и созданием папок
     *
     * @param $from источник
     * @param $to получатель
     * @return bool статус копирования
     */
    protected function copyFile($from, $to){
        $flag = false;
        if(file_exists($from) && $this->makeDir(pathinfo($to, PATHINFO_DIRNAME)) && copy($from, $to)){
            chmod($to, octdec($this->_config['new_file_permissions']));
            $flag = true;
        }
        return $flag;
    }

    /**
     * Создание папки
     *
     * @param $dir полный путь к папке которую необходимо создать
     * @return bool
     */
    public function makeDir($dir){
        $flag = true;
        if(!file_exists($dir)){
            $this->modx->getService('fileHandler','modFileHandler');
            $dirObj = $this->modx->fileHandler->make($dir, array(),'modDirectory');
            if(!is_object($dirObj) || !($dirObj instanceof modDirectory)) {
                $flag = false;
                $this->modx->log(modX::LOG_LEVEL_ERROR,'[phpthumbon] Could not get class modDirectory');
            }
            if($flag){
                if(!(is_dir($dir) || $dirObj->create())){
                    $flag = false;
                    $this->modx->log(modX::LOG_LEVEL_ERROR, "[phpthmbon] Could not create cache directory ".$this->_config['_cachePath']);
                }
            }
        }
        return $flag;
    }

    /**
     * Сброс текущих настроек
     *
     * @access public
     * @return bool всегда true
     */
    public function flush(){
        $this->_flag = true;
        $this->_config = array_merge(
            $this->_cfg,
            array('options'=>null,'input'=>null,'_options'=>null)
        );
        $this->_fileInfo = $this->_phpThumb = null;
        return true;
    }
}
