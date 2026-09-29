"""Describe private contact processing and the anonymous submission contract."""
from copy import deepcopy


def extend_contacts(spec):
    categories = ['監所探訪與代禱', '更生安置與職訓', '食品採購與禮盒', '志工加入', '奉獻與收據諮詢', '其他諮詢']
    schemas = spec['components']['schemas']
    paths = spec['paths']
    schemas['ContactSubmission'] = {'type': 'object', 'required': ['name', 'phone', 'category', 'message', 'submission_token'], 'properties': {
        'name': {'type': 'string', 'maxLength': 100}, 'phone': {'type': 'string', 'maxLength': 50},
        'email': {'type': 'string', 'format': 'email', 'nullable': True, 'maxLength': 254},
        'category': {'type': 'string', 'enum': categories}, 'message': {'type': 'string', 'maxLength': 10000},
        'submission_token': {'type': 'string', 'format': 'uuid'}, 'website': {'type': 'string', 'maxLength': 0},
    }}
    schemas['ContactInquiry'] = {'type': 'object', 'properties': {
        **{key: deepcopy(value) for key, value in schemas['ContactSubmission']['properties'].items() if key not in ['submission_token', 'website']},
        'id': {'type': 'integer'}, 'status': {'type': 'string', 'enum': ['new', 'processing', 'closed']},
        'staff_note': {'type': 'string', 'nullable': True}, 'version': {'type': 'integer', 'minimum': 1},
        'created_at': {'type': 'string', 'format': 'date-time'}, 'updated_at': {'type': 'string', 'format': 'date-time'},
        'handled_by_name': {'type': 'string', 'nullable': True},
    }}
    submit = deepcopy(paths['/contents']['post'])
    submit.update(summary='Submit a contact inquiry without login; CSRF and IP throttle required', operationId='post_public_contact', tags=['contact'], security=[])
    submit['requestBody'] = {'required': True, 'content': {'application/json': {'schema': {'$ref': '#/components/schemas/ContactSubmission'}}}}
    submit['responses']['200']['content'] = {'application/json': {'schema': {'type': 'object', 'properties': {'message': {'type': 'string'}, 'reference': {'type': 'string'}}}}}
    submit['responses']['409']['description'] = 'Same submission token was already used for different content.'
    submit['responses']['429'] = {'description': 'Submission rate limit exceeded.'}
    paths['/public/contact']['post'] = submit
    listing = deepcopy(paths['/contents']['get'])
    listing.update(summary='Private classified contact inbox; contacts.read.all required', operationId='get_contact_inquiries', tags=['contact'])
    listing['parameters'] = [{'name': name, 'in': 'query', 'required': False, 'schema': shape} for name, shape in [
        ('category', {'type': 'string', 'enum': categories}), ('status', {'type': 'string', 'enum': ['new', 'processing', 'closed']}), ('q', {'type': 'string'}),
    ]]
    listing['responses']['200']['content'] = {'application/json': {'schema': {'type': 'object', 'properties': {'data': {'type': 'array', 'items': {'$ref': '#/components/schemas/ContactInquiry'}}}}}}
    paths['/contact-inquiries'] = {'get': listing}
    update = deepcopy(paths['/contents/{id}']['put'])
    update.update(summary='Process inquiry status/note with version; contacts.update.all required', operationId='put_contact_inquiry', tags=['contact'])
    update['requestBody'] = {'required': True, 'content': {'application/json': {'schema': {'type': 'object', 'required': ['version', 'status'], 'properties': {
        'version': {'type': 'integer', 'minimum': 1}, 'status': {'type': 'string', 'enum': ['new', 'processing', 'closed']},
        'staff_note': {'type': 'string', 'nullable': True},
    }}}}}
    update['responses']['200']['content'] = {'application/json': {'schema': {'$ref': '#/components/schemas/ContactInquiry'}}}
    paths['/contact-inquiries/{id}'] = {'put': update}
