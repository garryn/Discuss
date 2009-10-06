Dis.panel.Board = function(config) {
    config = config || {};
    Ext.applyIf(config,{
        id: 'dis-panel-board'
        ,url: Dis.config.connector_url
        ,baseParams: {}
        ,items: [{
            html: '<h2>'+'New Board'+'</h2>'
            ,border: false
            ,id: 'dis-board-name'
            ,cls: 'modx-page-header'
        },{
            xtype: 'modx-tabs'
            ,deferredRender: false
            ,defaults: { autoHeight: true ,bodyStyle: 'padding: 1em;' }
            ,border: true
            ,items: [{
                title: _('general_information')
                ,layout: 'form'
                ,items: [{                    
                    xtype: 'hidden'
                    ,name: 'id'
                },{
                    xtype: 'hidden'
                    ,name: 'parent'
                    ,value: Dis.request.parent ? Dis.request.parent : 0
                },{
                    xtype: 'textfield'
                    ,fieldLabel: _('name')
                    ,name: 'name'
                    ,width: 250
                    ,allowBlank: false
                },{
                    xtype: 'dis-combo-category'
                    ,fieldLabel: 'Category'
                    ,name: 'category'
                    ,width: 250
                    ,allowBlank: false
                },{
                    xtype: 'textarea'
                    ,fieldLabel: 'Description'
                    ,name: 'description'
                    ,width: 500
                    ,grow: true
                },{
                    xtype: 'checkbox'
                    ,fieldLabel: 'Ignoreable'
                    ,name: 'ignoreable'
                    ,labelSeparator: ''
                    ,inputValue: 1
                }]
            },{
                title: 'Moderators'
                ,defaults: { autoHeight: true }
                ,items: [{
                    html: '<p>'+'This is a list of all the Moderators for this current board.'+'</p>'
                    ,border: false
                },{
                    xtype: 'dis-grid-board-moderators'
                    ,preventRender: true
                    ,board: config.board
                    ,width: '97%'
                }]
            },{
                title: 'User Group Access'
                ,defaults: { autoHeight: true }
                ,items: [{
                    html: '<p>'+'This is a list of all the User Groups that can access this current board. If none are specified, the Board will be globally accessible.'+'</p>'
                    ,border: false
                },{
                    xtype: 'dis-grid-board-usergroups'
                    ,preventRender: true
                    ,board: config.board
                    ,width: '97%'
                }]
            }]
        }]
        ,listeners: {
            'setup': {fn:this.setup,scope:this}
            ,'beforeSubmit': {fn:this.beforeSubmit,scope:this}
            ,'success': {fn:this.success,scope:this}
        }
    });
    Dis.panel.Board.superclass.constructor.call(this,config);
};
Ext.extend(Dis.panel.Board,MODx.FormPanel,{
    setup: function() {
        if (!this.config.board) return;
        Ext.Ajax.request({
            url: this.config.url
            ,params: {
                action: 'mgr/board/get'
                ,id: this.config.board
            }
            ,scope: this
            ,success: function(r) {
                r = Ext.decode(r.responseText);
                if (r.success) {
                    this.getForm().setValues(r.object);

                    if (r.object.moderators.length != 0) {
                        Ext.getCmp('dis-grid-board-moderators').getStore().loadData(r.object.moderators);
                    }
                    
                    if (r.object.usergroups.length != 0) {
                        Ext.getCmp('dis-grid-board-usergroups').getStore().loadData(r.object.usergroups);
                    }
                    
                    Ext.getCmp('dis-board-name').getEl().update('<h2>'+'Board'+': '+r.object.name+'</h2>');
                } else MODx.form.Handler.errorJSON(r);
            }
        });
    }
    ,beforeSubmit: function(o) {
        Ext.apply(o.form.baseParams,{
            moderators: Ext.getCmp('dis-grid-board-moderators').encode()
            ,usergroups: Ext.getCmp('dis-grid-board-usergroups').encode()
        });
    }
    ,success: function(o) {
        if (!this.config['board']) { 
            location.href = '?a='+Dis.request.a+'&action=board/update&board='+o.result.object.id;
        } else {
            Ext.getCmp('dis-btn-save').setDisabled(false);
            Ext.getCmp('dis-grid-board-moderators').getStore().commitChanges();
            Ext.getCmp('dis-grid-board-usergroups').getStore().commitChanges();
        }
    }
});
Ext.reg('dis-panel-board',Dis.panel.Board);