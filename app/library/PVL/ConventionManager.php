<?php
namespace PVL;

use \Entity\Convention;
use \Entity\ConventionArchive;

class ConventionManager
{
    /**
     * Routine synchronization process.
     * @throws \Zend_Exception
     */
    public static function run()
    {
        $em = \Zend_Registry::get('em');
        $created_threshold = strtotime('-1 month');
        $sync_threshold = time()-3600;

        $records = $em->createQuery('SELECT ca FROM Entity\ConventionArchive ca WHERE (ca.created_at >= :created) AND (ca.synchronized_at <= :synced) AND (ca.playlist_id IS NULL) ORDER BY ca.synchronized_at ASC')
            ->setParameter('created', $created_threshold)
            ->setParameter('synced', $sync_threshold)
            ->execute();

        if (count($records) > 0)
        {
            foreach ($records as $row)
                self::process($row);
        }
    }

    /**
     * Process an individual convention archive row.
     * @param ConventionArchive $row
     * @throws \Zend_Exception
     * @throws \Zend_Http_Client_Exception
     */
    public static function process(ConventionArchive $row)
    {
        $config = \Zend_Registry::get('config');
        $v3_api_key = $config->apis->youtube_v3;

        $client = new \Zend_Http_Client();
        $client->setConfig(array(
            'timeout'       => 20,
            'keepalive'     => true,
        ));
        $em = \Zend_Registry::get('em');

        $url = $row->web_url;

        if (empty($row->playlist_id))
        {
            switch($row->type)
            {
                case "yt_playlist":
                    $url_parts = \PVL\Utilities::parseUrl($url);
                    $playlist_id = $url_parts['query_arr']['list'];

                    if (!$playlist_id)
                        break;

                    // Clear existing related items.
                    $em->createQuery('DELETE FROM Entity\ConventionArchive ca WHERE ca.playlist_id = :id')
                        ->setParameter('id', $row->id)
                        ->execute();

                    // Get playlist information.
                    $client->setUri('https://www.googleapis.com/youtube/v3/playlists');
                    $client->setParameterGet(array(
                        'part'      => 'id,snippet',
                        'id'        => $playlist_id,
                        'maxResults' => 1,
                        'key'       => $v3_api_key,
                    ));

                    $response = $client->request('GET');

                    if ($response->isSuccessful())
                    {
                        $response_text = $response->getBody();
                        $data = @json_decode($response_text, TRUE);

                        $playlist = $data['items'][0]['snippet'];

                        $row->name = $playlist['title'];
                        $row->description = $playlist['description'];
                        $row->thumbnail_url = self::getThumbnail($playlist['thumbnails']);
                    }

                    // Get playlist contents.
                    $client->setUri('https://www.googleapis.com/youtube/v3/playlistItems');
                    $client->resetParameters();
                    $client->setParameterGet(array(
                        'part'      => 'id,snippet,status,contentDetails',
                        'playlistId' => $playlist_id,
                        'maxResults' => 50,
                        'key'       => $v3_api_key,
                    ));

                    $response = $client->request('GET');

                    if ($response->isSuccessful())
                    {
                        $response_text = $response->getBody();
                        $data = @json_decode($response_text, TRUE);

                        foreach((array)$data['items'] as $item)
                        {
                            $row_name = self::filterName($row, $item['snippet']['title']);
                            $row_thumb = self::getThumbnail($item['snippet']['thumbnails']);

                            // Apply name/thumbnail filtering to sub-videos.
                            if (!empty($row_name) && !empty($row_thumb))
                            {
                                $child_row = new ConventionArchive;
                                $child_row->convention = $row->convention;
                                $child_row->playlist_id = $row->id;
                                $child_row->type = 'yt_video';
                                $child_row->folder = $row->folder;

                                $child_row->name = $row_name;
                                $child_row->description = $item['snippet']['description'];
                                $child_row->web_url = 'http://www.youtube.com/watch?v=' . $item['contentDetails']['videoId'];
                                $child_row->thumbnail_url = $row_thumb;

                                $em->persist($child_row);
                            }
                        }
                    }

                    $row->synchronized_at = time();
                    $em->persist($row);
                break;

                case "yt_video":
                default:
                    // Pull video ID from any URL format.
                    if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?)/|.*[?&]v=)|youtu\.be/)([^"&?/ ]{11})%i', $url, $match))
                        $video_id = $match[1];
                    else
                        break;

                    // Reformat video URL to match standard format.
                    $row->web_url = 'http://www.youtube.com/watch?v='.$video_id;

                    // Pull data from API.
                    $client->setUri('https://www.googleapis.com/youtube/v3/videos');
                    $client->setParameterGet(array(
                        'id'        => $video_id,
                        'part'      => 'snippet,contentDetails',
                        'maxResults' => 1,
                        'key'       => $v3_api_key,
                    ));

                    $response = $client->request('GET');

                    if ($response->isSuccessful())
                    {
                        $response_text = $response->getBody();
                        $data = @json_decode($response_text, TRUE);

                        $video = $data['items'][0]['snippet'];

                        $row->name = self::filterName($row, $video['title']);
                        $row->description = $video['description'];
                        $row->thumbnail_url = self::getThumbnail($video['thumbnails']);

                        $row->synchronized_at = time();
                        $em->persist($row);
                    }
                break;
            }
        }

        $em->flush();
    }

    public static function filterName(ConventionArchive $row, $name)
    {
        $con = trim($row->convention->name);
        $name = trim($name);

        // Halt processing if video is private.
        if (strcmp($name, 'Private video') === 0)
            return false;

        // Strip con name off front of footage.
        if (substr(strtolower($name), 0, strlen($con)) == strtolower($con))
            $name = substr($name, strlen($con));

        // Strip con name off end of footage.
        if (substr(strtolower($name), 0-strlen($con)) == strtolower($con))
            $name = substr($name, 0, strlen($name)-strlen($con));

        $name = trim($name, " -@:\t\n\r\0");
        return $name;
    }

    public static function getThumbnail($thumbnails)
    {
        if ($thumbnails['medium'])
            return $thumbnails['medium']['url'];
        elseif ($thumbnails['maxres'])
            return $thumbnails['maxres']['url'];
        else
            return NULL;
    }
}