<?php
$smil = "<smil>
	<head>
	</head>
		<body>
			<switch>
        <video height='300' src='mobile/$video->filename.mp4' systemLanguage='eng' width='480'  title='Low'  system-bitrate='644100' />
        <video height='360' src='h264/$video->filename.mp4' systemLanguage='eng' width='640'   title='Medium' system-bitrate='1068100' />";
if (!EncodeTo::isAudio($video)) {
        $smil .= "<video height='720' src='HD720/$video->filename.mp4' systemLanguage='eng' width='1280' title='Medium High' system-bitrate='1544100' />";
        $smil .= "<video height='1080' src='1080p/$video->filename.mp4' systemLanguage='eng' width='1920' title='High' system-bitrate='3400000' />";
}
			$smil .="</switch>
		</body>
</smil>";

return $smil;
