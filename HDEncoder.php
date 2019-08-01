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
	* Attaches plugin methods to hooks in code base
	*/
	public function load() {
		Plugin::attachFilter( 'upload_info.encoder.complete' , array( __CLASS__ , 'hd_encode' ) );
	}

	/**
	* Prepend queueing command to original encoding command. 
	* 
 	*/
	public static function hd_encode() {
        // Check settings?  Submitted checkbox?  or keep running all by default?
	    // if ($makeHD) {

        
        $videoMapper = new VideoMapper();
        $userMapper = new UserMapper();
        $video = $videoMapper->getVideoByCustom(array('video_id' => $video_id, 'status' => VideoMapper::PENDING_CONVERSION));
        $user =$userMapper->getUserById($video->userId);


        $ffmpegPath = Settings::get('ffmpeg');
        $debugLog = LOG . '/' . $video->filename . '.log';
        $rawVideo = UPLOAD_PATH . '/temp/' . $video->filename . '.' . $video->originalExtension;

        $HD720TempFilePath = UPLOAD_PATH . '/HD720/' . $video->filename . '_temp.mp4';
        $HD720FilePath = UPLOAD_PATH . '/HD720/' . $video->filename . '.mp4';
        $HD720smilPath = UPLOAD_PATH . '/'. $video->filename . '.smil';


			$HD720Command = "$ffmpegPath -i $rawVideo " . Settings::get('HD720_encoding_options') . " $HD720TempFilePath >> $debugLog 2>&1";

			// Debug Log
			$logMessage = "\n\n\n\n==================================================================\n";
			$logMessage .= "H.264 720p ENCODING\n";
			$logMessage .= "==================================================================\n\n";
			$logMessage .= "H.264 720p Encoding Command: $HD720Command\n\n";
			$logMessage .= "H.264 720p Encoding Output:\n\n";
			$config->debugConversion ? App::log(CONVERSION_LOG, 'H.264 Encoding Command: ' . $HD720Command) : null;
			App::log($debugLog, $logMessage);

			// Execute H.264 encoding command
			exec($HD720Command);

			// Debug Log
			$config->debugConversion ? App::log(CONVERSION_LOG, 'Verifying H.264 video was created...') : null;

			// Verify temp H.264 video was created successfully
			if (!file_exists($HD720TempFilePath) || filesize($HD720TempFilePath) < 1024*5) {
		    	unlink('/var/local/spool/qdaemon/queue.lock');
				throw new Exception("The temp H.264 file for $video->userId $user->username was not created. The id of the video is: $video->videoId $video->title");
			}
			/////////////////////////////////////////////////////////////
			//                        STEP 1C                           //
			//            Shift Moov atom on H.264 video               //
			/////////////////////////////////////////////////////////////

			// Debug Log
			$config->debugConversion ? App::log(CONVERSION_LOG, "\nChecking qt-faststart permissions...") : null;

			if ((string) substr(sprintf('%o', fileperms($qt_faststart_path)), -4) != '0777') {
				try {
					Filesystem::setPermissions($qt_faststart_path, 0777);
				} catch (Exception $e) {
			    	unlink('/var/local/spool/qdaemon/queue.lock');
					throw new Exception("Unable to update permissions for qt-faststart. Please make sure it ($qt_faststart_path) has 777 executeable permissions.\n\nAdditional information: " . $e->getMessage());
				}
			}

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
			if (!file_exists($HD720FilePath) || filesize($HD720FilePath) < 1024*5) {
		    	//unlink('/var/local/spool/qdaemon/queue.lock');
				throw new Exception("The final H.264 720p file  for user $video->userId $user->username was not created. The id of the video is: $video->videoId $video->title");
			}
		}
//    }

}
