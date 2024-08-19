## Usage

### Basic Usage

To use the `YoutubeTranscript` package, create a new instance of the class by passing the YouTube video ID.

```php

use boonstoppel\YoutubeTranscript\YoutubeTranscript;

$videoId = 'your-youtube-video-id';
$translateLang = 'en';

$yt = new YoutubeTranscript($videoId);

$data = [
    'original' => $yt->fetchTranscriptData(),
    'translated' => $yt->fetchTranscriptData($translateLang)
];

```

Or with dependency injection:


```php

use App\Http\Controllers\Controller;
use boonstoppel\YoutubeTranscript\YoutubeTranscript;

class TranscriptController extends Controller
{
    public function __invoke(YoutubeTranscript $yt)
    {
        $yt->videoId = 'YOUR_VIDEO_ID';

        return [
            'original' => $yt->fetchTranscriptData(),
            'translated' => $yt->fetchTranscriptData(request('translate_lang'))
        ];
    }
}
