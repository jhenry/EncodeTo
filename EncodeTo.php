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
  public $description = 'Add support for additional encoding types (such as 720p and mp3) for uploaded media. Based on work by Wes Wright.';

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
  }

  /**
   * Encode an HD720p version of the video.
   * TODO: validate user, etc
   * TODO: clean up temp files.
   * TODO: create/set smil?
   */
  public static function hd_encode()
  {
    $video = EncodeTo::getVideoToEncode();

    if (strtolower($video->originalExtension) != 'mp3') {
      //$userMapper = new UserMapper();
      //$user = $userMapper->getUserById($video->userId);

      $encoderPaths = EncodeTo::getEncoderPaths($video);
      EncodeTo::HD720P($encoderPaths, $video, 'H.264 720p');
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
   * Encode to HD720p 
   * 
   * @param array $configs array of config variables. 
   * @param Video $video Video object
   * @param string $type type of file, i.e. temp, final, HD, mp3, etc. 
   */
  private static function HD720P($encoderPaths, $video, $type)
  {

    $encoderPaths = EncodeTo::getEncoderPaths($video);
    $encoderHDPaths = EncodeTo::getHDPaths($encoderPaths, $video);
    extract($encoderHDPaths);

    $command = "$ffmpegPath -i $rawVideo " . Settings::get('HD720_encoding_options') . " $tempFilePath >> $debugLogPath 2>&1";

    EncodeTo::debugLog("$type Encoding", $debugLogPath, $command);

    // Execute H.264 encoding command
    exec($command);

    EncodeTo::validateFileCreation($tempFilePath , $video, "temp $type");
    EncodeTo::shiftMoovAtom($encoderPaths, $video, $type);
  }
/**
   * Shift Moov atom for faster streaming start times.
   * 
   * @param array $configs array of config variables. 
   * @param Video $video Video object
   * @param string $type type of file, i.e. temp, final, HD, mp3, etc. 
   */
  private static function shiftMoovAtom($encoderVars, $video, $type)
  {

    $encoderPaths = EncodeTo::getHDPaths($encoderVars, $video);
    extract($encoderPaths);

    // Debug Log
    $config = Registry::get('config');
    $config->debugConversion ? App::log(CONVERSION_LOG, "\nShifting moov atom on $type video...") : null;

    // Prepare shift moov atom command
    $shiftMoovAtomCommand = "$qt_faststart_path $tempFilePath $filePath >> $debugLogPath 2>&1";

    EncodeTo::debugLog("$type Shift Moov Atom", $debugLogPath, $shiftMoovAtomCommand);

    // Execute shift moov atom command
    exec($shiftMoovAtomCommand);

    EncodeTo::createSMIL($smilPath, $video);

    EncodeTo::validateFileCreation($filePath , $video, "final $type");
  }

/**
   * Check to see if the file was created and output logs if not.
   * 
   * @param string $path path to file
   * @param Video $video Video object
   * @param string $type type of file, i.e. temp, final, HD, mp3, etc. 
   * TODO: log out exception
   */
  private static function validateFileCreation($path, $video, $type)
  {
    $config = Registry::get('config');
    $config->debugConversion ? App::log(CONVERSION_LOG, "Verifying $type file was created...") : null;

    // Verify file was created successfully
    if (!file_exists($path) || filesize($path) < 1024 * 5) {
      throw new Exception("The $type file  for user $video->userId $user->username was not created. The id of the video is: $video->videoId $video->title");
    }
  }
  /**
   * Set encoder paths from configs and filenames.
   * 
   * @param Video $video Video object
   */
  private static function getEncoderPaths($video)
  {
    $ffmpegPath = Settings::get('ffmpeg');
    $qt_faststart_path = Settings::get('qtfaststart');
    $debugLogPath = LOG . '/' . $video->filename . '.log';
    $rawVideo = UPLOAD_PATH . '/temp/' . $video->filename . '.' . $video->originalExtension;

    return compact('ffmpegPath', 'qt_faststart_path', 'debugLogPath', 'rawVideo');
  }

  /**
   * Create SMIL file.
   * 
   * @param array $smilPath path to smil file
   * @param Video $video Video object
   */
  private static function createSMIL($smilPath, $video)
  {
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
      Filesystem::write($smilPath,$content);
    } catch (Exception $e) {
      $config->debugConversion ? App::log(CONVERSION_LOG, "\nERROR - Problem creating SMIL file at $smilPath: $e") : null;
    }
  }
  /**
   * Set HD720 encoder paths from configs and filenames.
   * 
   * @param array $encoderPaths keyed array of path variables
   * @param Video $video Video object
   */
  private static function getHDPaths($encoderPaths, $video)
  {
    $baseHDPath = UPLOAD_PATH . '/HD720/';
    $encoderPaths["tempFilePath"] = $baseHDPath. $video->filename . '_temp.mp4';
    $encoderPaths["filePath"] = $baseHDPath . $video->filename . '.mp4';
    $encoderPaths["smilPath"] = UPLOAD_PATH . '/' . $video->filename . '.smil';

    return $encoderPaths;
  }
  /**
   * Format and output log info.
   * 
   * @param string $label label/type of log.
   * @param string $message log content
   * 
   */
  private static function debugLog($logType, $logPath, $command)
  {
    $config = Registry::get('config');
    $logMessage = "\n\n\n\n==================================================================\n";
    $logMessage .= "$logType\n";
    $logMessage .= "==================================================================\n\n";
    $logMessage .= "$logType Command: $command\n\n";
    $logMessage .= "$logType Output:\n\n";
    $config->debugConversion ? App::log(CONVERSION_LOG, "$logType Command: " . $command) : null;
    App::log($logPath, $logMessage);
  }
}
