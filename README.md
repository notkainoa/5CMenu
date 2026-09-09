# 5CMenu
API for https://menu.jojodmo.com. To use the API, use the endpoint `https://5cmenu-cache.jojodmo.com/v1/getMenu/?diningHall=<dining_hall>&startTime=<unix_timestamp>&language=en`, where `<dining_hall>` is one of mcconnel, collins, malott, frank, frary, oldenborg, or hoch. For example:

https://5cmenu-cache.jojodmo.com/v1/getMenu/?diningHall=hoch&startTime=1675670400&language=en

This returns the selected day plus the next six calendar days, for up to seven days total. Pass `days=1` through `days=7` to request a shorter window. The API only returns dates the upstream dining provider has published.

## Using this codebase

Everything here is pretty hacky and not well commented, so read at your own peril :)

The entry into the API is the `run` function in `api/menuParser.php`.

## Local menu checker

Run the API and browser checker together with Docker:

```sh
docker compose up --build
```

Open http://localhost:8080. The page requests every supported dining hall from the local PHP API, renders each meal and station, and marks empty or invalid responses as failures.

If port 8080 is already in use, choose another host port:

```sh
PORT=8055 docker compose up --build
```

Run the automated API check in another terminal:

```sh
node tests/ApiSmokeTest.mjs http://127.0.0.1:8080
```

The focused Bon Appétit parser checks run without a local PHP installation:

```sh
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli-alpine php tests/BonAppetitWebParserTest.php
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli-alpine php tests/LiveBonAppetitCheck.php
```
