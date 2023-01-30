![Logo Piglet](https://raw.githubusercontent.com/quimoniz/juph/master/img/logo.png)

# JUPH

**JU**kebox implemented in **P**HP/**H**TML5.

Makes a folder of media files (MP3, OGG, MPA, FLAC) accessible through a browser utilizing the HTML5 audio element.

While it relies heavily on Javascript (Frontend), PHP (Backend) and MySQL (Caching of it's Database), it provides functionality to:
- Aggregate songs into `Playlists`
- Listen to Online Radio Streams
- Keeping track of `Tags` for each song[^1]
- Edit MP3-Meta-Tags (using the `getid3` php library)[^2]
- Look at images of dancing pigs <img src="https://raw.githubusercontent.com/Quimoniz/juph/master/img/country.png" width="40" height="47">

[^1]: multiple thousands of tags have little performance impact due some sophisticated MySQL query optimization (i.e. through carefull picking of indices and favoring subselects instead of SQL-joining in certain places).

[^2]: It can read and **write** MP3-Meta-Tags right via an inline form in the browser.

**Note**: JUPH does not recognize separate users, it assumes that there is only one user who accesses the media files (albeit that one main user can be logged in with different browsers on different systems, each with their own separate `session playlist`)

## Overview

![Screen Shot](https://raw.githubusercontent.com/quimoniz/juph/master/juph-display.png)

