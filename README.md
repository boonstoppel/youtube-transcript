## Usage

### Basic Usage

To use the `YoutubeTranscript` package, create a new instance of the class by passing the YouTube video ID.

```php

$videoId = 'your-youtube-video-id';
$originalLang = 'zh-Hans';
$translateLang = 'en';

$yt = new YoutubeTranscript($videoId, $originalLang);

$data = [
    'original' => $yt->fetchTranscriptData(),
    'translated' => $yt->fetchTranscriptData($translateLang)
];
