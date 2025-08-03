counter = {}

request = function()
    wrk.method = "POST"
    wrk.headers["Content-Type"] = "application/json"
    wrk.headers["x-api-key"] = "rF0dVguLoeUUoVTQ"
    wrk.body = '{"vin": "MR2B19F33H1007504"}'
    return nil
end

response = function(status, headers, body)
    if counter[status] == nil then
        counter[status] = 0
    end
    counter[status] = counter[status] + 1
end

done = function(summary, latency, requests)
    for code, count in pairs(counter) do
        print(code .. " : " .. count)
    end
end

