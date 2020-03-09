<?php

class HDEncoder extends PluginAbstract
{
  /**
   * @var string Name of plugin
   */
  public $name = 'HDEncoder';

  /**
   * @var string Description of plugin
   */
  public $description = 'Add HD encoding for uploaded videos. Based on work by Wes Wright.';

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

    $config = Registry::get('config');
    if (isset($_SESSION['upload']->videoId)) {
      $video_id = $_SESSION['upload']->videoId;
    }

    $videoMapper = new VideoMapper();
    $userMapper = new UserMapper();
    $video = $videoMapper->getVideoByCustom(array('video_id' => $video_id, 'status' => VideoMapper::PENDING_CONVERSION));
    $user = $userMapper->getUserById($video->userId);

    $ffmpegPath = Settings::get('ffmpeg');
    $qt_faststart_path = Settings::get('qtfaststart');
    $debugLogPath = LOG . '/' . $video->filename . '.log';
    $rawVideo = UPLOAD_PATH . '/temp/' . $video->filename . '.' . $video->originalExtension;

    $HD720TempFilePath = UPLOAD_PATH . '/HD720/' . $video->filename . '_temp.mp4';
    $HD720FilePath = UPLOAD_PATH . '/HD720/' . $video->filename . '.mp4';
    $HD720smilPath = UPLOAD_PATH . '/' . $video->filename . '.smil';

    $HD720Command = "$ffmpegPath -i $rawVideo " . Settings::get('HD720_encoding_options') . " $HD720TempFilePath >> $debugLog 2>&1";

    HDEncode::debugLog('H.264 HD 720p Encoding', $debugLogPath, $HD720Command);

    // Execute H.264 encoding command
    exec($HD720Command);

    // Debug Log
    $config->debugConversion ? App::log(CONVERSION_LOG, 'Verifying H.264 video was created...') : null;

    // Verify temp H.264 video was created successfully
    if (!file_exists($HD720TempFilePath) || filesize($HD720TempFilePath) < 1024 * 5) {
      throw new Exception("The temp H.264 file for $video->userId $user->username was not created. The id of the video is: $video->videoId $video->title");
    }
    /////////////////////////////////////////////////////////////
    //                        STEP 1C                           //
    //            Shift Moov atom on H.264 video               //
    /////////////////////////////////////////////////////////////

    // Debug Log
    $config->debugConversion ? App::log(CONVERSION_LOG, "\nShifting moov atom on H.264 720p video...") : null;

    // Prepare shift moov atom command
    $HD720ShiftMoovAtomCommand = "$qt_faststart_path $HD720TempFilePath $HD720FilePath >> $debugLog 2>&1";

    // Debug Log
    $logMessage = "\n\n\n\n==================================================================\n";
    $logMessage .= "H.264 720p SHIFT MOOV ATOM\n";
    $logMessage .= "==================================================================\n\n";
    $logMessage .= "H.264 720p Shift Moov Atom Command: $HD720ShiftMoovAtomCommand\n\n";
    $logMessage .= "H.264 720p Shift Moov Atom Output:\n\n";
    $config->debugConversion ? App::log(CONVERSION_LOG, 'H.264720p Shift Moov Atom Command: ' . $HD720ShiftMoovAtomCommand) : null;
    App::log($debugLog, $logMessage);

    // Execute shift moov atom command
    exec($HD720ShiftMoovAtomCommand);

    // Debug Log
    $config->debugConversion ? App::log(CONVERSION_LOG, 'Verifying final H.264 720p file was created...') : null;

    // Verify H.264 720p video was created successfully
    if (!file_exists($HD720FilePath) || filesize($HD720FilePath) < 1024 * 5) {
      //unlink('/var/local/spool/qdaemon/queue.lock');
      throw new Exception("The final H.264 720p file  for user $video->userId $user->username was not created. The id of the video is: $video->videoId $video->title");
    }
  }

  /**
   * Format and output log info.
   * 
   * @param string $label label/type of log.
   * @param string $message log content
   * 
   */
  private function debugLog($logType, $logPath, $command)
  {
    $config = Registry::get('config');
    $logMessage = "\n\n\n\n==================================================================\n";
    $logMessage .= "$logType\n";
    $logMessage .= "==================================================================\n\n";
    $logMessage .= "$logType Command: $HD720Command\n\n";
    $logMessage .= "$logType Output:\n\n";
    $config->debugConversion ? App::log(CONVERSION_LOG, "$logType Encoding Command: " . $HD720Command) : null;
    App::log($logPath, $logMessage);
  }


}
