<?php

namespace boonstoppel\YoutubeTranscript;

use Illuminate\Support\Facades\Http;
use Exception;

class YoutubeTranscript
{   
        public $videoId;
        
        private static $youtubeBaseUrl = 'https://www.youtube.com/watch';

        private static $consentBaseUrl = 'https://consent.youtube.com/s';

        private $transcriptList = [];

        private $currentVideoId = null;

        private $originalLang;

    
        public function __construct($videoId = null)
        {
            $this->videoId = $videoId;

            $this->initTranscriptList();
        }

        public function fetchTranscriptData($lang = null) 
        {
            $this->initTranscriptList();

            if (!$lang) {
                $lang = $this->originalLang;
                $transcript = $this->transcriptList;
            } else if ($lang != $this->originalLang) {
                $transcript = $this->translateTranscript($lang);
            }
            
            $response = Http::get($transcript['url']);
            
            if ($response->status() !== 200) {
                throw new Exception("Failed to fetch transcript data.");
            }
            
            $xml = simplexml_load_string($response->body());
            $transcriptData = [];
    
            foreach ($xml->text as $text) {
                $transcriptData[] = [
                    'duration' => isset($text['dur']) ? (float)$text['dur'] : 0,
                    'start' => isset($text['start']) ? (float)$text['start'] : 0,
                    'text' => (string)$text,
                ];
            }
    
            return self::parseTranscriptionData($transcriptData);
        }

        public function setYoutubeId($videoId) {
            if ($videoId != $this->currentVideoId) {
                $this->transcriptList = null;
            }

            $this->videoId = $videoId;
        }

        private function initTranscriptList() {
            if (!$this->videoId || ($this->videoId == $this->currentVideoId && $this->transcriptList)) {
                return;
            }

            $transcriptLists = $this->fetchTranscriptList();

            $this->originalLang = array_key_first($transcriptLists);

            $this->currentVideoId = $this->videoId;
        
            $this->transcriptList = data_get($transcriptLists, $this->originalLang);
        }

        private function translateTranscript($targetLanguageCode) 
        {
            if (empty($this->transcriptList['translation_languages'])) {
                throw new Exception("This transcript is not translatable.");
            }
    
            $availableLanguages = array_column($this->transcriptList['translation_languages'], 'language_code');

            if (!in_array($targetLanguageCode, $availableLanguages)) {
                throw new Exception("Translation language not available: " . $targetLanguageCode);
            }
    
            $translatedUrl = $this->transcriptList['url'] . '&tlang=' . $targetLanguageCode;

            $translatedTranscript = $this->transcriptList;
            $translatedTranscript['url'] = $translatedUrl;
            $translatedTranscript['language'] = $targetLanguageCode;
    
            return $translatedTranscript;
        }

        private function fetchTranscriptList() 
        {
            $transcriptList = $this->buildTranscriptList(
                $this->extractCaptionsJson(
                    $this->fetchVideoHtml()
                )
            );

            return data_get($transcriptList, 'manually_created_transcripts', 
                data_get($transcriptList, 'generated_transcripts')
            );
        }
    
        private function fetchVideoHtml() 
        {
            $url = sprintf('%s?v=%s', 
                self::$youtubeBaseUrl, 
                $this->videoId
            );

            $response = Http::get($url);
            
            if ($response->status() !== 200) {
                throw new Exception("Failed to fetch video HTML for video ID: " . $this->videoId);
            }
    
            $html = $response->body();
    
            if (strpos($html, 'action="' . self::$consentBaseUrl . '"') !== false) {
                $this->createConsentCookie($html);

                $response = Http::get($url);
                
                $html = $response->body();
                if (strpos($html, 'action="' . self::$consentBaseUrl . '"') !== false) {
                    throw new Exception("Failed to create consent cookie for video ID: " . $this->videoId);
                }
            }
    
            return $html;
        }
    
        private function createConsentCookie($html) 
        {
            preg_match('/name="v" value="(.*?)"/', $html, $matches);
            
            if (empty($matches)) {
                throw new Exception("Failed to create consent cookie for video ID: " . $this->videoId);
            }

            setcookie('CONSENT', 'YES+' . $matches[1], time() + (86400 * 30), "/", ".youtube.com");
        }
    
        private function extractCaptionsJson($html) 
        {
            $splittedHtml = explode('"captions":', $html);
    
            if (count($splittedHtml) <= 1) {
                if (strpos($this->videoId, 'http://') === 0 || strpos($this->videoId, 'https://') === 0) {
                    throw new Exception("Invalid video ID: " . $this->videoId);
                }
                
                if (strpos($html, 'class="g-recaptcha"') !== false) {
                    throw new Exception("Too many requests for video ID: " . $this->videoId);
                }
                
                if (strpos($html, '"playabilityStatus":') === false) {
                    throw new Exception("Video unavailable: " . $this->videoId);
                }
    
                throw new Exception("Transcripts disabled for video ID: " . $this->videoId);
            }
    
            $captionsJson = json_decode(explode(',"videoDetails', $splittedHtml[1])[0], true);
            $captionsJson = $captionsJson['playerCaptionsTracklistRenderer'] ?? null;
    
            if ($captionsJson === null || !isset($captionsJson['captionTracks'])) {
                throw new Exception("No transcript available for video ID: " . $this->videoId);
            }
    
            return $captionsJson;
        }
    
        private function buildTranscriptList($captionsJson) 
        {
            $translationLanguages = array_map(function ($translationLanguage) {
                return [
                    'language' => $translationLanguage['languageName']['simpleText'],
                    'language_code' => $translationLanguage['languageCode']
                ];
            }, $captionsJson['translationLanguages'] ?? []);
    
            $generatedTranscripts = [];
            $manuallyCreatedTranscripts = [];
    
            foreach ($captionsJson['captionTracks'] as $caption) {
                $transcript = [
                    'url' => $caption['baseUrl'],
                    'language' => $caption['name']['simpleText'],
                    'language_code' => $caption['languageCode'],
                    'is_generated' => isset($caption['kind']) && $caption['kind'] === 'asr',
                    'translation_languages' => isset($caption['isTranslatable']) && $caption['isTranslatable'] ? $translationLanguages : []
                ];
    
                if ($transcript['is_generated']) {
                    $generatedTranscripts[$transcript['language_code']] = $transcript;
                } else {
                    $manuallyCreatedTranscripts[$transcript['language_code']] = $transcript;
                }
            }
    
            return [
                'manually_created_transcripts' => $manuallyCreatedTranscripts,
                'generated_transcripts' => $generatedTranscripts,
                'translation_languages' => $translationLanguages
            ];
        }

        private static function parseTranscriptionData($data) {
            return array_values(array_filter($data, function($item) {
                return !empty($item['text']);
            }));
        }
    }
