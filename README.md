# sdcovid
These are the scripts and such that I use for my [site tracking COVID-19 in San Diego County](http://www.sdcovid.today).

Feel free to adapt this to your purposes. The ingredients are:

1. Server with LEMP stack. 
2. Node and some npm packages (d3, moment, jsdom). These are for making the GIFs. 
3. ImageMagick. Also for making GIFs. I love GIFs. 
4. [Tau](https://github.com/theyak/Tau) for talking to MySQL because that's what we use at work. (Hi!)
5. MySQL database with three tables (see below).
6. ZIP code shapefile. I got mine from San Diego County. I changed their shapefile into GeoJSON and then put it in my `zip_shapes` table.
7. San Diego County's API.

The GIF-making script runs via cron overnight because it takes a very, very, very long time to run.

The reason that I have a database is that the County's API is kind of slow, and they only update things once a day.
It seems silly to ask a slow API for data that could not possibly have changed since the last time we checked. So I
save the data and then only check the API if there might be something new. Also, the County only allows you to get
a certain amount of data at a time, and I didn't want to break the requests up into pieces.

Still a work in progress, neeeds more error-checking, you know how it goes.

The MySQL tables look sort of like this:

```
CREATE TABLE `sd_daily_cases` (
  `id` int NOT NULL,
  `county_date` bigint NOT NULL,
  `tests` int DEFAULT NULL,
  `positives` int DEFAULT NULL,
  `hospitalized` int DEFAULT NULL,
  `icu` int DEFAULT NULL,
  `deaths` int DEFAULT NULL,
  `new_cases` int DEFAULT NULL,
  `new_tests` int DEFAULT NULL,
  `county_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `sd_daily_cases`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `sd_daily_cases`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
COMMIT;

CREATE TABLE `sd_zip_cases` (
  `id` int NOT NULL,
  `zip` varchar(5) NOT NULL,
  `case_count` int DEFAULT NULL,
  `updatedate` bigint NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `sd_zip_cases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `zip` (`zip`);

ALTER TABLE `sd_zip_cases`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
COMMIT;

CREATE TABLE `zip_shapes` (
  `id` int NOT NULL,
  `object_id` int NOT NULL,
  `ZIP` varchar(5) NOT NULL,
  `population` int NOT NULL,
  `community` varchar(40) NOT NULL,
  `geometry` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `zip_shapes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ZIP` (`ZIP`);

ALTER TABLE `zip_shapes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
COMMIT;
```
