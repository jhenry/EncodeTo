<?php

class EncodeTo extends PluginAbstract
{
  /**
   * @var string Name of plugin
   */
  public $name = 'EncodeTo';

  /**
   * @var string Description of plugin
   */
  public $description = 'Add support for additional encoding types (such as 720p) for uploaded media, and creation of SMIL for adaptive bitrate streaming. Based on work by Wes Wright.';

  /**
   * @var string Name of plugin author
   */
  public $author = 'Justin Henry';

  /**
   * @var string URL to plugin's website
   */
  public $url = 'https://uvm.edu/~jhenry/';

  /**
   * @var string Current version of plugin
   */
  public $version = '0.0.1';
  /**
   * Performs install operations for plugin. Called when user clicks install
   * plugin in admin panel.
   *
   */
  public function install()
  {
    Settings::set('HD720_encoding_options', '-acodec aac -b:a 128k -ac 2 -ar 44100  -af "aresample=first_pts=0" -pix_fmt yuv420p -vsync -1 -sn -vcodec libx264 -r 30 -vf "scale=min(1280\,trunc(iw/2)*2):trunc(ow/a/2)*2" -threads 0 -maxrate 3000k -bufsize 3000k -preset slower -profile:v high -tune film  -x264opts keyint=60:min-keyint=60:no-scenecut -map_metadata -1 -f mp4 -y');

    Filesystem::createDir(UPLOAD_PATH . '/HD720/');
  }
  /**
   * Attaches plugin methods to hooks in code base
   */
  public function load()
  {
    // Starting at top of upload completion controller, b/c we still have a videoId in the session vars
    Plugin::attachEvent('upload_complete.start', array(__CLASS__, 'hd_encode'));
    Plugin::attachEvent('upload_complete.start', array(__CLASS__, 'createSMIL'));
  }

  /**
   * Encode an HD720p version of the video.
   * TODO: validate user, etc
   * TODO: clean up temp files.
   */
  public static function hd_encode()
  {
    $video = EncodeTo::getVideoToEncode();

    if (!EncodeTo::isAudio($video)) {
      $config = Registry::get('config');
      $commandOutput = $config->debugConversion ? CONVERSION_LOG : '/dev/null';
      $command = Settings::get('php') . ' ' . DOC_ROOT . '/cc-content/plugins/EncodeTo/encode.php --video="' . $video->videoId . '" >> ' .  $commandOutput . ' 2>&1 &';

      if (class_exists('QEncoder')) 
      {
        $command = QEncoder::q_encoder($command);
      }

      exec('nohup ' . $command);
    }
  }

  /**
   * Get Video object based on uploaded video in _SESSION
   * 
   */
  private static function getVideoToEncode()
  {
    $videoMapper = new VideoMapper();

    if (isset($_SESSION['upload']->videoId)) {
      $video_id = $_SESSION['upload']->videoId;
      $video = $videoMapper->getVideoById($video_id);
      //$video = $videoMapper->getVideoByCustom(array('video_id' => $video_id, 'status' => VideoMapper::PENDING_CONVERSION));
      return $video;
    }
  }

/**
 * Checks to see if this is an audio file and that audio is allowed.
 * @param Video $video The video object to inspect.
 * @return bool True if audio's allowed and it's an audio file.
 */
private static function isAudio(Video $video) 
{
    if( class_exists( 'EnableAudio' ) ) {
        $audioFormats = json_decode(Settings::get('enable_audio_formats'));
        if( in_array($video->originalExtension, $audioFormats) ) {
            return true;
        }
    }
    return false;
}

  /**
   * Create SMIL file.
   * 
   * @param array $smilPath path to smil file
   * @param Video $video Video object
   */
  public static function createSMIL()
  {
    $video = EncodeTo::getVideoToEncode();
    $smilPath = UPLOAD_PATH . '/' . $video->filename . '.smil';
    $config = Registry::get('config');
    $config->debugConversion ? App::log(CONVERSION_LOG, "\nCreating SMIL file at $smilPath") : null;
    $content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
	<smil title=''>
	<head>
	</head>
		<body>
			<switch>
        <video height='300' src='mobile/$video->filename.mp4' systemLanguage='eng' width='480'  title='Low'  system-bitrate='644100' />
        <video height='360' src='h264/$video->filename.mp4' systemLanguage='eng' width='640'   title='Medium' system-bitrate='1068100' />
        <video height='720' src='HD720/$video->filename.mp4' systemLanguage='eng' width='1280' title='High' system-bitrate='1544100' />
			</switch>
		</body>
  </smil>";
    try{
      Filesystem::create($smilPath);
      Filesystem::write($smilPath,$content, false);
    } catch (Exception $e) {
      $config->debugConversion ? App::log(CONVERSION_LOG, "\nERROR - Problem creating SMIL file at $smilPath: $e") : null;
    }
  }
  
}
