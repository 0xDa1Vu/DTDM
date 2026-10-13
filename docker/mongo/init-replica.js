try {
  rs.status();
} catch (e) {
  if (e.code !== 94) throw e;
  const result = rs.initiate({
    _id: 'rsCatalog',
    members: [
      {_id: 0, host: 'mongo1:27017'},
      {_id: 1, host: 'mongo2:27017'},
      {_id: 2, host: 'mongo3:27017'}
    ]
  });
  if (!result.ok) throw new Error(JSON.stringify(result));
}
let ready = false;
for (let i = 0; i < 60; i++) {
  const status = rs.status();
  ready = status.members.filter(m => m.state === 1).length === 1 &&
    status.members.filter(m => m.state === 2).length === 2;
  if (ready) break;
  sleep(1000);
}
if (!ready) throw new Error('Replica set did not become ready');
printjson(rs.status().members.map(m => ({name: m.name, state: m.stateStr})));
