"""Generate the versioned OpenAPI reference from the reviewed route catalog."""
import json
import re
from pathlib import Path
from openapi_groups import extend_groups
from openapi_contacts import extend_contacts

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
GET,POST /prisons
PUT /prisons/{id}
GET /prisons/options
GET,POST /class-templates
POST /class-templates/preview
GET,PUT /class-templates/{id}
POST /class-templates/{id}/generate
GET,POST /sessions
GET,PUT /sessions/{id}
POST /sessions/{id}/assign
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
GET /public/products/{id}/quote
GET /public/search
GET,POST /products
GET,PUT,DELETE /products/{id}
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
POST /settings/logo
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
        if path.endswith('/quote'):
            operation['parameters'].extend([
                {'name': 'variant_id', 'in': 'query', 'required': True, 'schema': string},
                {'name': 'quantity', 'in': 'query', 'required': True, 'schema': {'type': 'integer', 'minimum': 1, 'maximum': 1000000}},
            ])
        if path.startswith(('/products', '/class-templates')) or path.endswith(('/quote', '/assign')):
            operation['description'] = 'Request fields, recurrence rules and pricing validation: docs/catalog-classes-v2.md.'
        if method != 'GET':
            operation['parameters'].append({'name': 'X-CSRF-TOKEN', 'in': 'header', 'required': True, 'schema': string, 'description': 'Fetch /auth/csrf with the current session. Refresh after login/logout.'})
            schema = change if '/assignments/' in path and not path.endswith('attendance') else {'type': 'object', 'additionalProperties': True}
            media = 'application/json'
            if path in ['/files', '/resources', '/settings/logo'] or path.endswith('/attendance'):
                media = 'multipart/form-data'
                schema = {'type': 'object', 'properties': {'file': {'type': 'string', 'format': 'binary'}, 'photo': {'type': 'string', 'format': 'binary'}, 'title': string, 'category': string, 'reason': string}}
            operation['requestBody'] = {'description': 'See contract.md for required module fields and validation.', 'content': {media: {'schema': schema}}}
        if path.startswith('/public/') or path in ['/health', '/auth/csrf', '/auth/login', '/auth/otp', '/auth/register', '/auth/reset-password']:
            operation['security'] = []
        paths[path][method.lower()] = operation
spec = {'openapi': '3.0.3', 'info': {'title': '3r Association API', 'version': '1.0.0', 'description': 'Same-origin session API. See contract.md for request/response fields. Schedule versions refer to the session. UAT mock integrations never charge or send external messages.'}, 'servers': [{'url': '/api/v1'}], 'security': [{'session': []}], 'paths': paths, 'components': {'securitySchemes': {'session': {'type': 'apiKey', 'in': 'cookie', 'name': 'r3_dev_session', 'description': 'Environment-specific session name; r3_uat_session on UAT.'}}, 'schemas': {'ScheduleChange': change}}}
target = Path(__file__).resolve().parents[1]/'docs'/'openapi.json'
article = {'type': 'object', 'properties': {
    'id': integer, 'kind': {'enum': ['news', 'page']}, 'title': string,
    'category': string, 'summary': string, 'author_name': string,
    'published_at': {'type': 'string', 'format': 'date-time'},
    'body_format': {'enum': ['text', 'html']}, 'body': string,
    'body_html': {'type': 'string', 'readOnly': True, 'description': 'Server-sanitized HTML, including escaped legacy text.'},
}}
spec['components']['schemas']['Article'] = article
prison = {'type': 'object', 'properties': {'id': integer, 'name': string, 'address': {'type': 'string', 'nullable': True}, 'active': {'type': 'boolean'}, 'version': integer}}
spec['components']['schemas']['Prison'] = prison
for prison_path in ['/prisons', '/prisons/{id}', '/prisons/options']:
    for operation in paths[prison_path].values():
        operation['description'] = 'Shared prison directory and inactive-reference rules: docs/prisons-cases.md.'
paths['/prisons']['get']['responses']['200']['content'] = {'application/json': {'schema': {'type': 'object', 'properties': {'data': {'type': 'array', 'items': {'$ref': '#/components/schemas/Prison'}}}}}}
paths['/sessions']['get']['parameters'].append({'name': 'prison_id', 'in': 'query', 'required': False, 'schema': integer})
paths['/public/news']['get']['parameters'].append({'name': 'limit', 'in': 'query', 'required': False, 'schema': {'type': 'integer', 'minimum': 1, 'maximum': 100}})
paths['/public/news']['get']['description'] = 'Published articles whose publication time has arrived, ordered by published_at descending then id descending. Homepage uses limit=3. See docs/articles.md.'
paths['/public/news']['get']['responses']['200']['content'] = {'application/json': {'schema': {'type': 'object', 'properties': {'data': {'type': 'array', 'items': {'$ref': '#/components/schemas/Article'}}}}}}
paths['/public/news/{id}']['get']['responses']['200']['content'] = {'application/json': {'schema': {'type': 'object', 'properties': {'data': {'$ref': '#/components/schemas/Article'}}}}}
extend_groups(spec)
extend_contacts(spec)
paths['/public/products']['get']['parameters'].extend([
    {'name': 'category', 'in': 'query', 'required': False, 'schema': {'type': 'string', 'maxLength': 100}},
    {'name': 'limit', 'in': 'query', 'required': False, 'schema': {'type': 'integer', 'minimum': 1, 'maximum': 100}},
])
paths['/public/products']['get']['responses']['200']['content'] = {'application/json': {'schema': {
    'type': 'object', 'properties': {
        'data': {'type': 'array', 'items': {'type': 'object', 'additionalProperties': True}},
        'categories': {'type': 'array', 'items': {'type': 'string'}, 'description': 'Categories of published products only, independent of the selected category.'},
    },
}}}
paths['/notifications']['get']['description'] = 'Notifications owned by the logged-in user, rechecked against current role and audience authorization. Relative action URLs are derived server-side; unread_count excludes inaccessible notices.'
paths['/notifications']['get']['responses']['200']['content'] = {'application/json': {'schema': {
    'type': 'object', 'properties': {
        'data': {'type': 'array', 'items': {'type': 'object', 'additionalProperties': True}},
        'unread_count': {'type': 'integer', 'minimum': 0},
    },
}}}
target.write_text(json.dumps(spec, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
print(f'Generated {len(paths)} API paths')
