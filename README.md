These files enabels you to fetch events from Hessen-Szene.de and use the within a loop in Etch (https://etchwp.com) 
In the file "hessen-szene.php" you need to replace the XML in line 39 by the XML feed you are using. Make sure, that the HTML for the description is used:

$feed_url = 'https://www.hessen-szene.de/cdn?type=151&tx_laks_calendar%5Baction%5D=xml&tx_laks_calendar%5Bcenter%5D=11&tx_laks_calendar%5BenableHtml%5D=1';
             ^
              Replace by your XML feed


Loop Configuration:

Use the following syntax:

{#loop options.events as item data-etch-context="eyJyZWYiOiIxbGJxdWY1In0="}

Use the item.FIELD_NAME as placeholder for your data - example:

{item.title} - Title
{item.date} - event date

See hessen-szene.php line 149 following for available keys to use.

There is an option for a limited display of events (line 241 of hessen-szene.php - adjust numbers of items to display by changing the value in line 247 from 4 to another value.

Use the following loop to display the limited events:

{#loop options.events_preview as item data-etch-context="eyJzdHJ1Y3R1cmVTdGF0ZSI6Im9wZW4iLCJyZWYiOiJraTV1bHEyIn0="}

For displaying a single event, create a page and use the {options.event.FIELD-NAME} to display the event fields. Use the URL /event-detail/?event={item.slug} to link to a single page (replace "vent-detail" by the slug of the paghe you use.

File "download_picture.php could be used to implement a download function for the pictures. 
