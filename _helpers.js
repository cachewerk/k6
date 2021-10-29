
export function wpMetrics(response) {
    if (! response.body) {
        return false;
    }

    const comment = response.body.match(/<!-- plugin=object-cache-pro (.+?) -->/g)

    if (! comment) {
        return false
    }

    const toCamelCase = function (str) {
        return str.toLowerCase()
            .replace(/['"]/g, '')
            .replace(/\W+/g, ' ')
            .replace(/ (.)/g, function ($1) { return $1.toUpperCase(); })
            .replace(/ /g, '')
    }

    const metrics = [...comment[0].matchAll(/metric#([\w-]+)=([\d.]+)/g)]
        .reduce(function(map, metric) {
            map[toCamelCase(metric[1])] = metric[2]

            return map
        }, {})

    return metrics
}
