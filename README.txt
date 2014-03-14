Hi,
This is NOT a real moodle plugin (but it conveniently reuses the db connection $DB !). This is only here to keep track of the link checker code that runs against the hub's table.

Note that the hub's table is in slightly different format when compared to registry table in moodle.org

The linkchecker will obtain data from hub table and work on it, however it wants to (in memory, or its own table etc) and then update the hub (directly via mysql for now, later on maybe via a web service so we can normalise (to registry table or mdl_hub_site_directory table's format) )

Note: perhaps this could become a plugin in future where automatic link checking/fingerprinting can happen from everywhere. This is so that with a ton of sites - this can be scaleable in future.

