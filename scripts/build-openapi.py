"""Generate the versioned OpenAPI reference from the reviewed route catalog."""
import json
import re
from pathlib import Path

catalog = '''
GET /health
GET /auth/csrf
POST /auth/login
POST /auth/logout
GET /auth/me
POST /auth/otp
POST /auth/register
POST /auth/reset-password
PUT /auth/profile
POST /auth/change-phone
GET /permissions
GET /role-options
GET,POST /roles
PUT /roles/{id}
GET,POST /users
PUT /users/{id}
GET /teachers
GET,POST /sessions
GET,PUT /sessions/{id}
POST /assignments/{id}/leave
POST /assignments/{id}/withdraw-leave
POST /assignments/{id}/invite
POST /assignments/{id}/replace
POST /assignments/{id}/attendance
GET /invitations
POST /invitations/{id}/respond
GET /changes
POST /changes/{id}/acknowledge
GET /notifications
POST /notifications/{id}/read
GET /reports
GET /public/pages
GET /public/contact
GET /public/pages/{slug}
GET /public/news
GET /public/news/{id}
GET /public/products
GET /public/products/{id}
GET /public/search
GET,POST /contents
GET,PUT,DELETE /contents/{id}
POST /files
GET /files/{id}/download
GET,POST /resources
DELETE /resources/{id}
GET,POST /cases
GET /cases/export
GET,PUT,DELETE /cases/{id}
POST /cases/{id}/records
GET,POST /meetings
GET,PUT,DELETE /meetings/{id}
GET,POST /forms
GET,PUT,DELETE /forms/{id}
GET,POST /forms/{id}/responses
GET /forms/{id}/export
GET,POST /donations
POST /donations/{id}/simulate
GET,PUT /integrations/line
GET /integrations/drive
POST /integrations/drive/simulate
GET,PUT /settings
'''
string = {'type': 'string'}
integer = {'type': 'integer'}
change = {'type': 'object', 'required': ['version', 'reason'], 'properties': {'version': integer, 'reason': string, 'teacher_id': integer, 'override_conflict': {'type': 'boolean'}, 'attendance_resolution': {'enum': ['void']}}}
paths = {}
for line in catalog.strip().splitlines():
    methods, path = line.split()
    paths[path] = {}
    for method in methods.split(','):
        operation = {'summary': method+' '+path, 'operationId': method.lower()+re.sub('[^a-zA-Z0-9]', '_', path), 'tags': [path.split('/')[1]], 'parameters': [], 'responses': {'200': {'description': 'Success. Lists use {data:[]}; detailed shapes in contract.md.'}, '401': {'description': 'Login required'}, '403': {'description': 'Role or data scope denied'}, '409': {'description': 'Stale session version, scheduling conflict or invalid transition'}, '422': {'description': 'Validation failed'}}}
        for name in re.findall(r'{(.*?)}', path):
            operation['parameters'].append({'name': name, 'in': 'path', 'required': True, 'schema': integer if name == 'id' else string})
        if method != 'GET':
            operation['parameters'].append({'name': 'X-CSRF-TOKEN', 'in': 'header', 'required': True, 'schema': string, 'description': 'Fetch /auth/csrf with the current session. Refresh after login/logout.'})
            schema = change if '/assignments/' in path and not path.endswith('attendance') else {'type': 'object', 'additionalProperties': True}
            media = 'application/json'
            if path in ['/files', '/resources'] or path.endswith('/attendance'):
                media = 'multipart/form-data'
                schema = {'type': 'object', 'properties': {'file': {'type': 'string', 'format': 'binary'}, 'photo': {'type': 'string', 'format': 'binary'}, 'title': string, 'category': string, 'reason': string}}
            operation['requestBody'] = {'description': 'See contract.md for required module fields and validation.', 'content': {media: {'schema': schema}}}
        if path.startswith('/public/') or path in ['/health', '/auth/csrf', '/auth/login', '/auth/otp', '/auth/register', '/auth/reset-password']:
            operation['security'] = []
        paths[path][method.lower()] = operation
spec = {'openapi': '3.0.3', 'info': {'title': '3r Association API', 'version': '1.0.0', 'description': 'Same-origin session API. See contract.md for request/response fields. Schedule versions refer to the session. UAT mock integrations never charge or send external messages.'}, 'servers': [{'url': '/api/v1'}], 'security': [{'session': []}], 'paths': paths, 'components': {'securitySchemes': {'session': {'type': 'apiKey', 'in': 'cookie', 'name': 'r3_dev_session', 'description': 'Environment-specific session name; r3_uat_session on UAT.'}}, 'schemas': {'ScheduleChange': change}}}
target = Path(__file__).resolve().parents[1]/'docs'/'openapi.json'
target.write_text(json.dumps(spec, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
print(f'Generated {len(paths)} API paths')
