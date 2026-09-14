const baseURL = process.argv[2] || "http://127.0.0.1:8080";
const date = process.argv[3] || new Intl.DateTimeFormat("en-CA", {
    timeZone: "America/Los_Angeles",
    year: "numeric",
    month: "2-digit",
    day: "2-digit"
}).format(new Date());

const halls = (process.env.HALLS || "collins,mcconnel,malott,frank,frary,oldenborg,hoch").split(",");
let failed = false;

function normalizeDate(value){
    const digits = String(value || "").replaceAll(/[^0-9]/g, "");
    return digits.length === 8 ? `${digits.slice(0, 4)}-${digits.slice(4, 6)}-${digits.slice(6, 8)}` : String(value || "").slice(0, 10);
}

await Promise.all(halls.map(async hall => {
    const url = new URL("/api/menu.php", baseURL);
    url.searchParams.set("diningHall", hall);
    url.searchParams.set("startDate", date);
    url.searchParams.set("days", "1");
    url.searchParams.set("source", "live");
    url.searchParams.set("developer", "true");

    try{
        const response = await fetch(url);
        const payload = await response.json();
        let meals = 0;
        let stations = 0;
        let items = 0;
        const matchingDays = (payload.menu || []).filter(day => normalizeDate(day?.date) === date);
        const closed = payload.diningHallOpen === false || (matchingDays.length > 0 && matchingDays.every(day => day.open === false));
        for(const day of matchingDays){
            for(const meal of Object.values(day.meals || {})){
                meals += 1;
                stations += (meal.stations || []).length;
                for(const station of meal.stations || []){items += (station.menu || []).length;}
            }
        }
        const ok = response.ok && Array.isArray(payload.menu) && matchingDays.length > 0 && (closed || (meals > 0 && stations > 0 && items > 0));
        console.log(`${ok ? "PASS" : "FAIL"} ${hall} status=${response.status} state=${closed ? "closed" : "open"} meals=${meals} stations=${stations} items=${items}`);
        if(!ok){
            failed = true;
            if(payload.error || payload.detail){console.log(`  ${payload.error || ""} ${payload.detail || ""}`.trimEnd());}
        }
    }
    catch(error){
        failed = true;
        console.log(`FAIL ${hall} ${error.message}`);
    }
}));

process.exitCode = failed ? 1 : 0;
