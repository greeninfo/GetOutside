<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource_Active extends EventDataSource {

var $table            = 'eventdatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('event',);

var $option_fields = array(
    'url'     => NULL,
    'option1' => array('required'=>TRUE, 'name'=>"API Key", 'help'=>"Your Active.com API key for the Event Search API v2"),
    'option2' => array('required'=>FALSE, 'name'=>"Org ID", 'help'=>"Optional: Enter your Organization GUID to filter the listings, showing only your events from Active.com. To find your Organization GUID, contact Active.com technical support."),
    'option3' => NULL,
    'option4' => NULL,
    'option5' => NULL,
    'option6' => NULL,
    'option7' => NULL,
    'option8' => NULL,
    'option9' => NULL,
);

// one of very few event data source types, where location exists reliably and in computer-readable form
// reloadContent() will repopulate EventLocations while it updates the Events
var $supports_location = TRUE;


public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','Active.com API');
}



/**********************************************************************************************
 * INSTANCE METHODS
 **********************************************************************************************/

/*
 * reloadContent()
 * Connect to the data source and grab all the events listed, and save them as local Events.
 * By design this is destructive: all existing Events for this EventDataSource are deleted in favof of these new ones.
 */
public function reloadContent() {
    // no API key, that's a problem; even if it's given we check below anyway cuz it may be revoked, expired, or just plain wrong
    $apikey = $this->option1;
    $orgid  = $this->option2;
    if (! preg_match('/^\w+$/', $apikey)) throw new EventDataSourceErrorException('Active.com Event Search API v2 requires an API key.');
    if ($orgid and !preg_match('/^[\w\-]+$/', $orgid)) throw new EventDataSourceErrorException('The specified Organization ID is not valid.');

    // make up the API calls
    // - first fetch is with 0 items per page; gets us a "total_results" attribute so we can begin paging over the results
    // - filter by Org ID (all org events) or else by region (bounding box of the city, 50 miles radius)
    // - the &bbox= param does not work (always empty resultset) so use &lat_lon= and &radius=    (too bad, would be great to use the selected area in the admin panel)
    // - no need to filter by start_date and end_date as expired events won't be in the feed anyway

    $params = array();
    $params['sort']         = 'date_asc';
    $params['category']     = 'event';
    $params['per_page']     = 0;
    if ($orgid) {
        $params['org_id'] = $orgid;
    } else {
        $lat = 0.5 * ( $this->siteconfig->get('bbox_s') + $this->siteconfig->get('bbox_n') );
        $lon = 0.5 * ( $this->siteconfig->get('bbox_w') + $this->siteconfig->get('bbox_e') );
        $params['lat_lon']      = sprintf("%.5f,%.5f", $lat, $lon );
        $params['radius']       = 50;
    }
    $url = sprintf('http://api.amp.active.com/v2/search?api_key=%s&%s', $apikey, http_build_query($params) );
    //throw new EventDataSourceErrorException($url);

    // make this first request, which is only to figure out how many records we will be fetching
    $content = @json_decode(@file_get_contents($url));
    if (!$content) throw new EventDataSourceErrorException("No result structure. Check that this API key is active for the Activity Search API v2");
    if (!$content->total_results) throw new EventDataSourceErrorException("No results. Check that there are in fact activities in this area.");

    // now iterate in pages of 1000, but with a hard limit of 10,000 in all cuz 10 trips to their server is plenty of waiting
    // and collect a big ol' list of all of the event entries
    $collected_events = array();
    $params['per_page'] = 1000;
    if ($content->total_results > 10*$params['per_page']) $content->total_results = 10*$params['per_page'];
    $pages = ceil($content->total_results / $params['per_page']);
    for ($page=1; $page<=$pages; $page++) {
        $params['current_page'] = $page;
        $url     = sprintf('http://api.amp.active.com/v2/search?api_key=%s&%s', $apikey, http_build_query($params) );
        $content = @json_decode(@file_get_contents($url));
        foreach ($content->results as $entry) $collected_events[] = $entry;
    }
    //throw new EventDataSourceErrorException(sprintf("Collected %d raw entries", sizeof($collected_events) ));

    // guess we're good! delete the existing Events in this source...
    // and also any EventLocations, cuz MySQL isn't smart enough to cacade-delete...
    foreach ($this->event as $old) {
        foreach ($old->eventlocation as $l) $l->delete();
        $old->delete();
    }

    // ... then load the new ones
    $success    = 0;
    $failed     = 0;
    $nolocation = 0;
    foreach ($collected_events as $entry) {
        // if this Event lacks a location, bail
        // this is later used to generate an EventLocation
        $lat = (float) @$entry->place->geoPoint->lat;
        $lon = (float) @$entry->place->geoPoint->lon;
        if (! $lat or ! $lon) { $nolocation++; continue; }

        // find an URL
        $url = @$entry->assetLegacyData->seoUrl; if (! $url) $url = @$entry->registrationUrlAdr;

        $event = new Event();
        $event->eventdatasource_id  = $this->id;
        $event->remoteid            = $entry->assetGuid;
        $event->starts              = strtotime($entry->activityStartDate); // Unix timestamp
        $event->ends                = strtotime($entry->activityEndDate); // Unix timestamp
        $event->name                = substr($entry->assetName,0,100);
        $event->url                 = $url;
        $event->description         = (string) @$entry->assetDescriptions[0]->description;

        // name is required
        if (!$event->name) { $failed++; continue; }

        // now, figure out what weekdays intersect this event's duration; sat  sun  mon  ...
        // these are used to quickly search for "events on a Saturday"
        $event->mon = $event->tue = $event->wed = $event->thu = $event->fri = $event->sat = $event->sun = 0;
        for ($thistime=$event->starts; $thistime<$event->ends; $thistime+=86400) {
            $wday = strtolower(date('D',$thistime));
            $event->{$wday} = 1;

            // tip: if all 7 days are a Yes by now, just skip the rest
            if ($event->mon and $event->tue and $event->wed and $event->thu and $event->fri and $event->sat and $event->sun) break;
        }

        // Gender requirements?   Active.com API has regReqGenderCd which may be M F or blank
        // blank in all cases I've ever seen to date, so this is based on asking tech support...
        // see mobile/index.phtml for the official list of coded values AND BE AWARE THAT MySQL forces these to be STRINGS AND NOT NUMBERS
        // this would be used for filtering events by gender, which may or may not be of ultimate use since no events here have that field populated
        switch ($entry->regReqGenderCd) {
            case 'M':
                $event->audience_gender = '1';
                break;
            case 'F':
                $event->audience_gender = '2';
                break;
            default:
                $event->audience_gender = '0';
                break;
        }

        // Age requirements?  Active.com has integer fields (well, integer strings) regReqMinAge and regReqMaxAge
        // and we should massage this to find the best match 0-5: 1=Infants, 2=Preschool, 3=Youth/Teens, 4=Adults, 5=Senior, 0=All Ages
        // these aren't really seen in the data feed so far, and are based on asking Active.com's tech support
        // see mobile/index.phtml for the official list of coded values AND BE AWARE THAT MySQL forces these to be STRINGS AND NOT NUMBERS
        // this would be used for filtering events by age, which may or may not be of ultimate use since no events here have that field populated
        $event->audience_age = '0'; // start with All Ages by default
        $entry->regReqMinAge = (integer) $entry->regReqMinAge;
        $entry->regReqMaxAge = (integer) $entry->regReqMaxAge;
        if ($entry->regReqMinAge >= 40) {
            $event->audience_age = '5';
        } else if ($entry->regReqMinAge >= 18) {
            $event->audience_age = '4';
        } else if ($entry->regReqMaxAge <= 25) {
            $event->audience_age = '3';
        } else if ($entry->regReqMaxAge <= 5) {
            $event->audience_age = '2';
        } else if ($entry->regReqMaxAge <= 3) {
            $event->audience_age = '1';
        }

        // ready!
        $event->save();
        $success++;

        // DONE with the Event itself; now create the EventLocation
        $loc = new EventLocation();
        $loc->event_id      = $event->id;
        $loc->latitude      = $lat;
        $loc->longitude     = $lon;
        $loc->title         = (string) $entry->place->placeName;
        $loc->subtitle      = sprintf("%s %s %s", $entry->place->addressLine1Txt, $entry->place->addressLine2Txt, $entry->place->cityName );
        $loc->save();
    }

    // update our last_fetch date
    $this->last_fetch = time();
    $this->save();

    // guess we're done and happy; throw an error  (ha ha)
    $message = array();
    $message[] = "Successfully loaded $success events.";
    if ($failed)        $message[] = "$failed events skipped due to blank/missing name.";
    if ($nolocation)    $message[] = "$nolocation events lacked a location.";
    $message = implode("\n",$message);
    throw new EventDataSourceSuccessException($message);
}


/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
