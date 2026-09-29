"""Apply the reviewed group/audience extension to the generated API catalog."""
from copy import deepcopy


def extend_groups(spec):
    paths = spec['paths']
    schemas = spec['components']['schemas']
    ids = {'type': 'array', 'items': {'type': 'integer'}, 'uniqueItems': True}
    article = schemas['Article']['properties']
    article.update({
        'article_type': {'type': 'string', 'enum': ['news', 'sharing', 'testimony']},
        'visibility': {'type': 'string', 'enum': ['public', 'groups']},
        'group_ids': deepcopy(ids),
        'group_names': {'type': 'array', 'items': {'type': 'string'}},
        'version': {'type': 'integer', 'minimum': 1},
    })
    schemas['GroupWrite'] = {
        'type': 'object', 'required': ['name', 'active', 'member_ids'],
        'properties': {
            'name': {'type': 'string', 'maxLength': 100},
            'description': {'type': 'string', 'nullable': True, 'maxLength': 5000},
            'active': {'type': 'boolean'}, 'member_ids': {**deepcopy(ids), 'maxItems': 500},
            'version': {'type': 'integer', 'minimum': 1, 'description': 'Required for PUT; mismatch returns 409.'},
        },
    }
    schemas['Group'] = {'allOf': [
        {'$ref': '#/components/schemas/GroupWrite'},
        {'type': 'object', 'properties': {
            'id': {'type': 'integer'},
            'members': {'type': 'array', 'items': {'type': 'object', 'properties': {
                'id': {'type': 'integer'}, 'name': {'type': 'string'}, 'active': {'type': 'boolean'},
            }}}, 'member_count': {'type': 'integer'},
        }},
    ]}
    schemas['Broadcast'] = {'type': 'object', 'properties': {
        'id': {'type': 'integer'}, 'content_id': {'type': 'integer'}, 'version': {'type': 'integer'},
        'recipient_count': {'type': 'integer'}, 'sent_at': {'type': 'string', 'format': 'date-time'},
        'duplicate': {'type': 'boolean'}, 'line_mode': {'type': 'string', 'enum': ['mock']},
    }}

    def query(name, schema):
        return {'name': name, 'in': 'query', 'required': False, 'schema': schema}

    paths['/public/news']['get']['parameters'] += [
        query('article_type', {'type': 'string', 'enum': ['news', 'sharing', 'testimony']}),
        query('category', {'type': 'string'}),
    ]
    paths['/public/news']['get']['description'] += ' Public visibility only, even for authenticated calls. Filters are applied before limit. See groups-articles.md.'
    for path in ['/resources', '/meetings']:
        paths[path]['get']['parameters'].append(query('group_id', {'type': 'integer'}))
        paths[path]['get']['description'] = 'Functional permission and role/group audience checked before filtering. Group membership does not grant a role.'
    for path, method in [('/contents', 'post'), ('/contents/{id}', 'put'), ('/meetings', 'post'), ('/meetings/{id}', 'put')]:
        props = paths[path][method]['requestBody']['content']['application/json']['schema'].setdefault('properties', {})
        props['group_ids'] = deepcopy(ids)
        if path.startswith('/contents'):
            props.update({key: deepcopy(article[key]) for key in ['article_type', 'visibility', 'version']})
    paths['/resources']['post']['requestBody']['content']['multipart/form-data']['schema']['properties']['group_ids'] = deepcopy(ids)

    def operation(path, method, description, request=None, response=None, listing=False):
        template = deepcopy(paths['/contents/{id}'][method] if method in ['get', 'put'] else paths['/contents']['post'])
        template.update(summary=description, description=description + ' See groups-articles.md.', tags=['groups'],
                        operationId=method + '_' + path.strip('/').replace('/', '_').replace('{', '').replace('}', ''))
        template['parameters'] = [item for item in template['parameters'] if item['in'] != 'path']
        if '{id}' in path:
            template['parameters'].insert(0, {'name': 'id', 'in': 'path', 'required': True, 'schema': {'type': 'integer'}})
        if request:
            template['requestBody'] = {'required': True, 'content': {'application/json': {'schema': request}}}
        else:
            template.pop('requestBody', None)
        if response:
            shape = {'$ref': '#/components/schemas/' + response}
            if listing:
                shape = {'type': 'object', 'properties': {'data': {'type': 'array', 'items': shape}}}
            template['responses']['200']['content'] = {'application/json': {'schema': shape}}
        template['responses']['404'] = {'description': 'Not found or private group content unavailable to this viewer.'}
        paths.setdefault(path, {})[method] = template

    operation('/groups', 'get', 'Manage all groups and membership; groups.manage.all required', response='Group', listing=True)
    operation('/groups', 'post', 'Create a group; groups.manage.all required', request={'$ref': '#/components/schemas/GroupWrite'}, response='Group')
    operation('/groups/{id}', 'get', 'Read group membership; groups.manage.all required', response='Group')
    operation('/groups/{id}', 'put', 'Update group with version check; groups.manage.all required', request={'$ref': '#/components/schemas/GroupWrite'}, response='Group')
    operation('/groups/options', 'get', 'Active group id/name options for current permitted scope')
    operation('/groups/member-options', 'get', 'Member id/name/active options; groups.manage.all required; no phone disclosure')
    operation('/group-news', 'get', 'Published group messages for current members or content.read.all', response='Article', listing=True)
    operation('/group-news/{id}', 'get', 'Read group message with current membership check', response='Article')
    paths['/group-news/{id}']['get']['responses']['200']['content']['application/json']['schema'] = {
        'type': 'object', 'properties': {'data': {'$ref': '#/components/schemas/Article'}},
    }
    operation('/contents/{id}/broadcast', 'post',
              'Send station notifications plus LINE mock once per article version; content.publish.all and groups.broadcast.all required',
              request={'type': 'object', 'required': ['version'], 'properties': {'version': {'type': 'integer', 'minimum': 1}}}, response='Broadcast')
    operation('/contents/{id}/broadcasts', 'get', 'Broadcast history; content.publish.all and groups.broadcast.all required', response='Broadcast', listing=True)
    operation('/resources/{id}', 'put', 'Update resource metadata; resources.update.all required; preserve omitted scope and original file',
              request={'type': 'object', 'properties': {
                  'title': {'type': 'string'}, 'category': {'type': 'string'},
                  'role_ids': deepcopy(ids), 'group_ids': deepcopy(ids),
              }})
