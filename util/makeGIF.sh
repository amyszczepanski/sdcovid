#!/bin/bash

framedest=/var/www/html/assets/frames/zip_data.json
/usr/bin/touch "$framedest"

cd /var/www/html/
jsondata=$(/usr/bin/php /var/www/html/fetch_and_parse_zip_code_data.php)

echo "$jsondata" > "$framedest"

cd /var/www/html/assets/frames

startdate=1585555200
enddate=$(grep -Po '"max_date":.*?[^\\]",' zip_data.json | grep -Po '"max_date":.*?[^\\]",'|awk -F':' '{print $2}' | head -c 10)
extent=$((($enddate-$startdate)/(3600*24)))

for (( i=0; i<$extent; i++ ))
do
	/usr/bin/node /var/www/html/util/makeFrames.js $i
  printf -v j "%02d" $i
	/usr/bin/convert mapframe$j.svg mapframe$j.png
done

/usr/bin/convert -delay 20 -loop 0 mapframe*.png sdzipmap.gif
/usr/bin/convert sdzipmap.gif \( +clone -set delay 400 \) +swap +delete sdzipmap.gif
/usr/bin/mv sdzipmap.gif ../sdzipmap.gif

/usr/bin/rm zip_data.json
/usr/bin/rm *.svg